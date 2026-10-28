<script setup>
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    liste: { type: Object, required: true },
});

const fmtNb = (v, decimales = 0) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: decimales });

const pageTitle = computed(() => 'Tous les ABC');
const pageDescription = computed(() =>
    `Annuaire des ${props.liste.total.toLocaleString('fr-FR')} Atlas de la Biodiversité Communale : recherche et filtres par statut, territoire, démographie (population, superficie, densité), aires protégées et patrimoine écologique.`
);

/* Formulaire : champs éditables en local, la validation repart en page 1
   avec uniquement les valeurs non vides dans l'URL (partageable). */
const champs = reactive({
    q: props.liste.query.q ?? '',
    statut: props.liste.query.statut ?? '',
    region: props.liste.query.region ?? '',
    departement: props.liste.query.departement ?? '',
    source: props.liste.query.source ?? '',
    ami_ofb: props.liste.query.ami_ofb === true,
    annee_min: props.liste.query.annee_min ?? '',
    annee_max: props.liste.query.annee_max ?? '',
    pop_min: props.liste.query.pop_min ?? '',
    pop_max: props.liste.query.pop_max ?? '',
    surf_min: props.liste.query.surf_min ?? '',
    densite_min: props.liste.query.densite_min ?? '',
    patrimoine: props.liste.query.patrimoine ?? '',
    espece: props.liste.query.espece ?? '',
    habitat: props.liste.query.habitat ?? '',
});

/* Paramètres réellement filtrants : sert à libeller le compteur de
   résultats (« X ABC correspondent à vos critères » vs « au total »). */
const CLES_FILTRES = ['q', 'statut', 'region', 'departement', 'source', 'ami_ofb', 'annee_min', 'annee_max',
    'pop_min', 'pop_max', 'surf_min', 'densite_min', 'patrimoine', 'espece', 'habitat'];

const filtresActifs = computed(() =>
    CLES_FILTRES.some((cle) => props.liste.query[cle] !== null && props.liste.query[cle] !== '')
);

const appliquer = () => {
    const data = {};
    for (const [cle, valeur] of Object.entries(champs)) {
        // La checkbox AMI OFB est booléenne : on envoie explicitement
        // '1'/'0' dans l'URL (le service ne reconnait pas true/false).
        if (cle === 'ami_ofb') {
            if (valeur) data[cle] = '1';
            continue;
        }
        if (valeur !== '' && valeur !== null && valeur !== undefined) data[cle] = valeur;
    }
    router.get('/abc', data, { preserveScroll: true });
};

const reinitialiser = () => router.get('/abc', {}, { preserveScroll: false });

/* URL de tri / pagination : repart de la query string courante (état de
   l'URL, pas du formulaire en cours d'édition) et change un paramètre.
   Les valeurs par défaut (page 1, tri nom asc) sont omises de l'URL. */
const urlAvec = (modifs) => {
    const fusion = { ...props.liste.query, ...modifs };
    const p = new URLSearchParams();
    for (const [cle, valeur] of Object.entries(fusion)) {
        if (valeur === null || valeur === undefined || valeur === '') continue;
        if (cle === 'page' && Number(valeur) === 1) continue;
        p.set(cle, String(valeur));
    }
    if (p.get('tri') === 'nom') {
        p.delete('tri');
        if (p.get('sens') === 'asc') p.delete('sens');
    }
    const qs = p.toString();
    return qs ? `/abc?${qs}` : '/abc';
};

const triUrl = (colonne) => urlAvec({
    tri: colonne,
    sens: props.liste.query.tri === colonne && props.liste.query.sens !== 'desc' ? 'desc' : 'asc',
    page: 1,
});

const flecheTri = (colonne) => {
    if (props.liste.query.tri !== colonne) return '';
    return props.liste.query.sens === 'desc' ? ' ↓' : ' ↑';
};

const statutClass = (s) =>
    ({ en_cours: 'badge-st-en_cours', a_venir: 'badge-st-a_venir', va_debuter: 'badge-st-a_venir', termine: 'badge-st-termine', inconnu: 'badge-st-inconnu' })[s] || 'badge-st-inconnu';

const patrimoineTitle = (item) =>
    `${item.nb_aires} aire(s) protégée(s), ${item.nb_znieff} ZNIEFF, ${item.nb_natura} site(s) Natura 2000 touchant le périmètre`;

const communesTitle = (item) => {
    if (!item.communes?.length) return '';
    const noms = item.communes.map((c) => c.libelle).join(', ');
    return item.nb_communes_suivantes > 0 ? `${noms}… et ${item.nb_communes_suivantes} autre(s)` : noms;
};

const territoire = (item) => [item.region_label, item.departement_label].filter(Boolean).join(' · ') || '—';

const annee = (item) => {
    if (!item.annee_debut) return '—';
    return item.annee_fin && item.annee_fin !== item.annee_debut ? `${item.annee_debut}–${item.annee_fin}` : String(item.annee_debut);
};
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" canonical="/abc" />
        <div class="container py-4">
            <Breadcrumbs :items="[{ label: 'Tous les ABC' }]" />
            <h1 class="h3 mb-2">Tous les ABC</h1>
            <p class="text-muted">
                Tous les Atlas de la Biodiversité Communale suivis par l'Observatoire — passés, en cours et à venir.
                Recherchez par nom, commune ou structure porteuse, filtrez par statut, territoire, démographie ou patrimoine
                écologique, et triez sur chaque colonne. Les critères restent dans l'URL : un résultat est partageable.
            </p>

            <form class="card card-body bg-light border-0 mb-4" @submit.prevent="appliquer">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label class="form-label small mb-1" for="f-q">Recherche</label>
                        <input id="f-q" v-model="champs.q" type="search" class="form-control form-control-sm" placeholder="Nom de l'ABC, commune, structure porteuse…" />
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="f-statut">Statut</label>
                        <select id="f-statut" v-model="champs.statut" class="form-select form-select-sm">
                            <option value="">Tous</option>
                            <option v-for="s in liste.filtres.statuts" :key="s.valeur" :value="s.valeur">{{ s.label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="f-region">Région</label>
                        <select id="f-region" v-model="champs.region" class="form-select form-select-sm">
                            <option value="">Toutes</option>
                            <option v-for="(label, slug) in liste.filtres.regions" :key="slug" :value="slug">{{ label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="f-dept">Département</label>
                        <select id="f-dept" v-model="champs.departement" class="form-select form-select-sm">
                            <option value="">Tous</option>
                            <option v-for="d in liste.filtres.departements" :key="d.code" :value="d.code">{{ d.label }} ({{ d.code.toUpperCase() }})</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="f-source">Source</label>
                        <select id="f-source" v-model="champs.source" class="form-select form-select-sm">
                            <option value="">Toutes</option>
                            <option v-for="(label, valeur) in liste.filtres.sources" :key="valeur" :value="valeur">{{ label }}</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-end mt-1">
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-annee-min">Année min</label>
                        <input id="f-annee-min" v-model="champs.annee_min" type="number" min="1900" max="2200" class="form-control form-control-sm" inputmode="numeric" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-annee-max">Année max</label>
                        <input id="f-annee-max" v-model="champs.annee_max" type="number" min="1900" max="2200" class="form-control form-control-sm" inputmode="numeric" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-pop-min">Population min</label>
                        <input id="f-pop-min" v-model="champs.pop_min" type="number" min="0" class="form-control form-control-sm" inputmode="numeric" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-pop-max">Population max</label>
                        <input id="f-pop-max" v-model="champs.pop_max" type="number" min="0" class="form-control form-control-sm" inputmode="numeric" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-surf-min">Superficie min (km²)</label>
                        <input id="f-surf-min" v-model="champs.surf_min" type="number" min="0" step="0.1" class="form-control form-control-sm" inputmode="decimal" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-densite-min">Densité min (hab./km²)</label>
                        <input id="f-densite-min" v-model="champs.densite_min" type="number" min="0" step="0.1" class="form-control form-control-sm" inputmode="decimal" />
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="f-patrimoine">Patrimoine écologique</label>
                        <select id="f-patrimoine" v-model="champs.patrimoine" class="form-select form-select-sm">
                            <option value="">Tous les ABC</option>
                            <option value="znieff">Dans une ZNIEFF</option>
                            <option value="natura">Dans un site Natura 2000</option>
                            <option value="aires">Avec une aire protégée</option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-2">
                        <label class="form-label small mb-1" for="f-espece">Espèce (INPN)</label>
                        <input id="f-espece" v-model="champs.espece" type="search" class="form-control form-control-sm" placeholder="Nom ou cd_ref TAXREF" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <label class="form-label small mb-1" for="f-habitat">Habitat (Natura)</label>
                        <input id="f-habitat" v-model="champs.habitat" type="search" class="form-control form-control-sm" placeholder="ex. 2110" />
                    </div>
                    <div class="col-6 col-lg-1">
                        <div class="form-check small mt-4">
                            <input id="f-ami" v-model="champs.ami_ofb" class="form-check-input" type="checkbox" />
                            <label class="form-check-label" for="f-ami">AMI OFB</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-success btn-sm">Filtrer</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="reinitialiser">Réinitialiser</button>
                </div>
            </form>

            <p class="fs-5 mb-3" aria-live="polite">
                <strong>{{ fmtNb(liste.total) }}</strong> ABC
                {{ filtresActifs ? (liste.total > 1 ? 'correspondent à vos critères' : 'correspond à vos critères') : 'au total' }}
                <span v-if="liste.pages > 1" class="text-muted small">— page {{ liste.page }} sur {{ liste.pages }}</span>
            </p>

            <p class="small text-muted">
                Démographie : sommes des populations légales et superficies IGN des communes du périmètre (communes
                « à vérifier » écartées) — à considérer comme des ordres de grandeur, notamment pour les périmètres
                multi-communes. Patrimoine : zonages INPN touchant au moins une commune du périmètre.
            </p>

            <div v-if="liste.items.length" class="table-responsive">
                <table class="table table-sm table-striped align-middle small">
                    <thead>
                        <tr>
                            <th><Link class="text-reset text-decoration-none" :href="triUrl('nom')" title="Trier par nom">ABC{{ flecheTri('nom') }}</Link></th>
                            <th><Link class="text-reset text-decoration-none" :href="triUrl('statut')" title="Trier par statut">Statut{{ flecheTri('statut') }}</Link></th>
                            <th><Link class="text-reset text-decoration-none" :href="triUrl('annee')" title="Trier par année de début">Années{{ flecheTri('annee') }}</Link></th>
                            <th>Territoire</th>
                            <th class="text-end"><Link class="text-reset text-decoration-none" :href="triUrl('communes')" title="Trier par nombre de communes">Communes{{ flecheTri('communes') }}</Link></th>
                            <th class="text-end"><Link class="text-reset text-decoration-none" :href="triUrl('population')" title="Trier par population du périmètre">Population{{ flecheTri('population') }}</Link></th>
                            <th class="text-end"><Link class="text-reset text-decoration-none" :href="triUrl('superficie')" title="Trier par superficie du périmètre">Superficie (km²){{ flecheTri('superficie') }}</Link></th>
                            <th class="text-end"><Link class="text-reset text-decoration-none" :href="triUrl('densite')" title="Trier par densité du périmètre">Densité{{ flecheTri('densite') }}</Link></th>
                            <th class="text-end"><Link class="text-reset text-decoration-none" :href="triUrl('aires')" title="Trier par nombre d'aires protégées">Aires protégées{{ flecheTri('aires') }}</Link></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in liste.items" :key="item.id">
                            <td>
                                <Link class="text-reset text-decoration-none" :href="`/abc/${item.slug}`" :title="`Fiche ${item.nom}`">{{ item.nom }}</Link>
                                <div v-if="item.structure_porteuse" class="text-muted">{{ item.structure_porteuse }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="statutClass(item.statut)">{{ item.statut_label }}</span>
                                <span v-if="item.statut_verifie" class="ms-1 small text-muted" title="Statut confirmé par une vérification manuelle">✓</span>
                            </td>
                            <td>{{ annee(item) }}</td>
                            <td>{{ territoire(item) }}</td>
                            <td class="text-end" :title="communesTitle(item)">{{ fmtNb(item.nb_communes) }}</td>
                            <td class="text-end">{{ item.population ? fmtNb(item.population) : '—' }}</td>
                            <td class="text-end">{{ item.superficie_km2 ? fmtNb(item.superficie_km2, 1) : '—' }}</td>
                            <td class="text-end">{{ item.densite ? fmtNb(item.densite, 1) : '—' }}</td>
                            <td class="text-end" :title="patrimoineTitle(item)">{{ item.nb_aires || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="alert alert-light">
                Aucun ABC ne correspond à ces critères. Essayez d'élargir la recherche.
            </div>

            <nav v-if="liste.pages > 1" aria-label="Pagination ABC" class="mt-3">
                <ul class="pagination pagination-sm">
                    <li class="page-item" :class="{ disabled: liste.page <= 1 }">
                        <Link class="page-link" :href="urlAvec({ page: liste.page - 1 })" :aria-disabled="liste.page <= 1">Précédent</Link>
                    </li>
                    <li class="page-item disabled"><span class="page-link">Page {{ liste.page }} / {{ liste.pages }}</span></li>
                    <li class="page-item" :class="{ disabled: liste.page >= liste.pages }">
                        <Link class="page-link" :href="urlAvec({ page: liste.page + 1 })" :aria-disabled="liste.page >= liste.pages">Suivant</Link>
                    </li>
                </ul>
            </nav>
        </div>
    </Layout>
</template>
