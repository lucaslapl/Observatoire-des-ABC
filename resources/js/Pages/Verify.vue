<script setup>
import { ref, computed, reactive } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const props = defineProps({
    projets: { type: Array, default: () => [] },
    compteurs: { type: Object, default: () => ({}) },
    candidats: { type: Array, default: () => [] },
});

const fmtMontant = (m) => {
    const n = Number(String(m ?? '').replace(',', '.'));
    return Number.isFinite(n) && n > 0 ? new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(n) : '';
};

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const isAdmin = computed(() => !!user.value?.roles?.includes('admin'));
const isModerateur = computed(() => !!user.value?.roles?.includes('moderateur'));
// Admin et modérateur (expert de confiance) : verdict direct, sourcé.
const peutTrancher = computed(() => isAdmin.value || isModerateur.value);
// Membre vérifié (contributeur) : peut suggérer, pas trancher.
const isContributeur = computed(() => !!user.value && user.value.email_verifie && !peutTrancher.value);

const ETATS = {
    a_verifier: { label: 'À vérifier', cls: '' },
    confirme_termine: { label: '✓ Confirmé terminé', cls: 'confirme_termine' },
    confirme_en_cours: { label: 'Confirmé en cours', cls: 'confirme_en_cours' },
    toujours_a_avenir: { label: 'Toujours à venir', cls: 'toujours_a_avenir' },
    confirme_date: { label: '✓ Date vérifiée', cls: 'confirme_termine' },
    introuvable: { label: 'Introuvable', cls: 'introuvable' },
    douteux: { label: 'Incertain', cls: 'douteux' },
};
// Les motifs renvoyés par l'API (contrat parité, cf. GeoJsonParityTest)
// sont des libellés français ; on n'a ici que leur classe CSS.
const MOTIF_CLS = {
    'potentiellement terminé': 'termine',
    'potentiellement en cours': 'en_cours',
    'archives 2022': 'archives',
    'anomalie': 'anomalie',
};

const SUGG_TYPES = { statut: 'Statut', date_debut: 'Dates de début / fin', note: 'Note', lien: 'Lien', autre: 'Autre info' };
const SUGG_STATUTS = { termine: 'Terminé', en_cours: 'En cours', va_debuter: 'À venir' };

const sMotif = ref('');
const sEtat = ref('a_verifier');
const sQ = ref('');

const forms = reactive({});
const form = (p) => forms[p.id] || (forms[p.id] = { etat: p.etat, note: p.note || '', lien: p.lien || '', annee_debut: p.annee_debut || '', annee_fin: p.annee_fin || '' });

const suggs = reactive({});
const sugg = (p) => suggs[p.id] || (suggs[p.id] = {
    type: 'statut', statut: 'termine', annee_debut: '', annee_fin: '', source: '',
    note: '', lien: '', texte: '', commentaire: '', sending: false, message: '', ok: false,
});

const fache = reactive({ type: '', message: '', login: false });

const searchUrl = (p) => 'https://duckduckgo.com/?q=' + encodeURIComponent(p.requete);

const filtered = computed(() => {
    const q = sQ.value.trim().toLowerCase();
    return props.projets.filter((p) => {
        if (sMotif.value && !p.motifs.includes(sMotif.value)) return false;
        if (sEtat.value === 'verifies') {
            if (p.etat === 'a_verifier') return false;
        } else if (sEtat.value === 'a_verifier') {
            if (p.etat !== 'a_verifier' || p.motifs.length === 0) return false;
        } else if (sEtat.value && p.etat !== sEtat.value) return false;
        if (q) {
            const hay = `${p.nom} ${p.communes || ''} ${p.structure_porteuse || ''} ${p.departements || ''}`.toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });
});

const aVerifierCount = computed(() => props.projets.filter((p) => p.etat === 'a_verifier' && p.motifs.length > 0).length);
const verifiesCount = computed(() => props.projets.filter((p) => p.etat !== 'a_verifier').length);

const faits = computed(() => {
    const c = props.compteurs;
    return (c.confirme_termine || 0) + (c.confirme_en_cours || 0) + (c.toujours_a_avenir || 0) + (c.confirme_date || 0) + (c.introuvable || 0) + (c.douteux || 0);
});

const rowClass = (p) => ({ 'table-success': p.etat === 'confirme_termine', 'table-danger': p.etat === 'douteux' });

const badges = (p) => {
    const etat = ETATS[p.etat] || ETATS.a_verifier;
    const list = p.etat !== 'a_verifier' ? [{ label: etat.label, cls: etat.cls }] : [];
    p.motifs.forEach((b) => list.push({ label: b, cls: MOTIF_CLS[b] || '' }));
    return list;
};

const formatDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '';

const save = async (p, f) => {
    fache.type = '';
    fache.message = '';
    fache.login = false;
    const body = { projet_id: p.id, etat: f.etat, note: f.note, lien: f.lien };
    if (f.etat === 'confirme_date') {
        const an = Number(f.annee_debut);
        if (an) body.annee_debut = an;
        const fin = Number(f.annee_fin);
        if (fin) body.annee_fin = fin;
    }
    try {
        await axios.post('/api/verifications', body);
        const target = props.projets.find((x) => x.id === p.id);
        target.etat = f.etat;
        target.note = f.note;
        target.verifie_le = new Date().toISOString();
        target.verifie_par = user.value?.name || null;
        if (f.etat === 'confirme_date') {
            target.annee_debut = Number(f.annee_debut) || target.annee_debut;
            target.annee_fin = Number(f.annee_fin) || target.annee_fin;
        }
        fache.type = 'success';
        fache.message = 'Vérification enregistrée.';
    } catch (err) {
        fache.type = 'danger';
        if (err.response?.status === 401) {
            fache.message = 'Connexion requise : seuls les administrateurs et modérateurs peuvent enregistrer un verdict.';
            fache.login = true;
        } else {
            fache.message = 'Erreur : ' + (err.response?.data?.error || err.message);
        }
    }
};

const sendSuggestion = async (p) => {
    const s = sugg(p);
    s.message = '';
    s.ok = false;
    const body = { projet_id: p.id, type: s.type };
    if (s.commentaire) body.commentaire = s.commentaire;
    if (s.type === 'statut') body.statut_suggere = s.statut;
    if (s.type === 'date_debut') {
        const an = Number(s.annee_debut);
        if (!an) {
            s.message = "Veuillez renseigner au moins l'année de début.";
            return;
        }
        body.annee_debut_suggeree = an;
        const fin = Number(s.annee_fin);
        if (fin) body.annee_fin_suggeree = fin;
        if (s.source) body.source = s.source;
    }
    if (s.type === 'note') body.note = s.note;
    if (s.type === 'lien') body.lien = s.lien;
    if (s.type === 'autre') {
        if (!s.texte.trim()) {
            s.message = 'Décrivez la correction à apporter.';
            return;
        }
        body.texte = s.texte;
    }
    s.sending = true;
    try {
        await axios.post('/api/contributions', body);
        s.ok = true;
        s.message = 'Merci, suggestion envoyée : elle sera examinée par un administrateur avant application.';
        s.commentaire = '';
    } catch (err) {
        const statut = err.response?.status;
        if (statut === 401) {
            s.message = 'Connexion requise : seuls les membres inscrits peuvent suggérer une correction.';
        } else if (statut === 403) {
            s.message = 'Vérifiez votre adresse email (lien reçu par mail) pour pouvoir envoyer une suggestion.';
        } else {
            s.message = 'Erreur : ' + (err.response?.data?.error || err.message);
        }
    } finally {
        s.sending = false;
    }
};
</script>

<template>
    <Layout>
        <Seo noindex title="Vérification des ABC" description="Worklist de vérification manuelle des Atlas de la Biodiversité Communale." />
        <div class="container py-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h1 class="h4 mb-0">Vérification des ABC</h1>
                <span class="badge text-bg-light">{{ faits }}/{{ projets.length }} vérifiés</span>
            </div>

            <div v-if="!user" class="alert alert-info py-2">
                <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
                Vous consultez la liste publique des projets à vérifier.
                <a href="/register?raison=contribution">Créez un compte</a> ou
                <a href="/login">connectez-vous</a> pour suggérer une correction :
                elle sera examinée par un administrateur avant application.
            </div>

            <div v-if="fache.message" class="alert alert-{{ fache.type }} alert-dismissible fade show pb-2 pt-2" role="alert">
                {{ fache.message }}
                <a v-if="fache.login" href="/login" class="btn btn-sm btn-dark ms-2">Se connecter</a>
                <button type="button" class="btn-close" @click="fache.message = ''"></button>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-3 py-2 mb-3 bg-white border rounded-3 px-3">
                <label class="d-flex align-items-center gap-2 me-2" style="font-size:13px; white-space:nowrap;">Motif
                    <select v-model="sMotif" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="potentiellement terminé">Potentiellement terminé</option>
                        <option value="potentiellement en cours">Potentiellement en cours</option>
                        <option value="archives 2022">Archives 2022</option>
                        <option value="anomalie">Commune incohérente</option>
                        <option value="date inconnue">Date inconnue</option>
                    </select>
                </label>
                <label class="d-flex align-items-center gap-2 me-2" style="font-size:13px; white-space:nowrap;">État
                    <select v-model="sEtat" class="form-select form-select-sm">
                        <option value="a_verifier">À vérifier</option>
                        <option value="">Tous</option>
                        <option value="verifies">Vérifiés</option>
                        <option value="confirme_termine">Confirmé terminé</option>
                        <option value="confirme_en_cours">Confirmé en cours</option>
                        <option value="toujours_a_avenir">Toujours à venir</option>
                        <option value="confirme_date">Date vérifiée</option>
                        <option value="introuvable">Introuvable</option>
                        <option value="douteux">Incertain</option>
                    </select>
                </label>
                <label class="d-flex align-items-center gap-2 me-2" style="font-size:13px; white-space:nowrap;">Recherche
                    <input v-model="sQ" type="text" class="form-control form-control-sm" style="width:200px"
                        placeholder="Nom, commune, structure…" />
                </label>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge text-bg-secondary">{{ aVerifierCount }} à vérifier</span>
                    <span v-if="verifiesCount" class="badge text-bg-light" title="Projets déjà vérifiés — visibles via le filtre État"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ verifiesCount }} vérifiés</span>
                    <span v-if="compteurs.confirme_termine" class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ compteurs.confirme_termine }} terminés</span>
                    <span v-if="compteurs.confirme_en_cours" class="badge text-bg-warning">{{ compteurs.confirme_en_cours }} en cours</span>
                    <span v-if="compteurs.toujours_a_avenir" class="badge text-bg-warning">{{ compteurs.toujours_a_avenir }} à venir</span>
                    <span v-if="compteurs.confirme_date" class="badge text-bg-info">{{ compteurs.confirme_date }} dates vérifiées</span>
                    <span v-if="compteurs.introuvable" class="badge text-bg-secondary">{{ compteurs.introuvable }} introuvables</span>
                    <span v-if="compteurs.douteux" class="badge text-bg-danger">{{ compteurs.douteux }} incertains</span>
                </div>
            </div>

            <div class="small text-muted mb-2">
                Les projets déjà vérifiés restent consultables : passez le filtre
                « État » sur <b>Vérifiés</b> ou <b>Tous</b>. Une erreur sur un projet confirmé ?
                <template v-if="peutTrancher">Re-sauvegardez un verdict corrigé, ou signalez-la depuis le popup de la carte.</template>
                <template v-else-if="isContributeur">Envoyez une suggestion ci-contre, ou signalez-la depuis le popup de la carte.</template>
                <template v-else>Les membres connectés peuvent envoyer une suggestion, examinée avant application.</template>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle bg-white shadow-sm rounded-3 overflow-hidden mb-0">
                    <thead>
                        <tr>
                            <th>Projet</th>
                            <th>Communes</th>
                            <th>Début</th>
                            <th>Motif</th>
                            <th>Recherche</th>
                            <th v-if="peutTrancher" style="min-width:290px">Verdict</th>
                            <th v-else-if="user" style="min-width:290px">Suggestion de correction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in filtered" :key="p.id" :class="rowClass(p)">
                            <td>
                                <div class="nom">{{ p.nom }}</div>
                                <div class="sub">{{ p.structure_porteuse || '—' }}{{ p.departements ? ' · ' + p.departements : '' }}</div>
                            </td>
                            <td class="communes" :title="p.communes || ''">{{ p.communes || '—' }}</td>
                            <td>{{ p.annee_debut || '—' }}</td>
                            <td>
                                <span v-for="(b, i) in badges(p)" :key="i" class="badge-cell" :class="b.cls">{{ b.label }}</span>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" :href="searchUrl(p)"><i class="bi bi-search me-1" aria-hidden="true"></i>Rechercher</a>
                                <a v-if="p.lien" class="btn btn-sm btn-outline-secondary" :href="p.lien" target="_blank" rel="noopener"><i class="bi bi-globe me-1" aria-hidden="true"></i>Lien</a>
                            </td>
                            <td v-if="peutTrancher">
                                <div class="form">
                                    <select class="form-select form-select-sm" v-model="form(p).etat">
                                        <option v-for="(v, k) in ETATS" :key="k" :value="k">{{ v.label }}</option>
                                    </select>
                                    <input type="text" placeholder="Note" class="form-control form-control-sm" v-model="form(p).note" />
                                    <input type="text" placeholder="Lien trouvé" class="form-control form-control-sm" v-model="form(p).lien" />
                                    <div v-if="form(p).etat === 'confirme_date'" class="d-flex gap-1 w-100">
                                        <input type="number" min="1990" max="2040" placeholder="Début" class="form-control form-control-sm" style="width:90px" v-model="form(p).annee_debut" />
                                        <input type="number" min="1990" max="2040" placeholder="Fin" class="form-control form-control-sm" style="width:80px" v-model="form(p).annee_fin" />
                                    </div>
                                    <button class="btn btn-sm btn-success" @click="save(p, form(p))">Sauver</button>
                                    <span v-if="p.etat !== 'a_verifier' && p.verifie_le" class="saved"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ formatDate(p.verifie_le) }}<template v-if="p.verifie_par"> · {{ p.verifie_par }}</template></span>
                                </div>
                            </td>
                            <td v-else-if="user">
                                <template v-if="!user.email_verifie">
                                    <div class="small text-muted">
                                        Vérifiez votre adresse email (lien reçu à l'inscription) pour pouvoir envoyer une suggestion.
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="form">
                                        <select class="form-select form-select-sm" style="width:auto" v-model="sugg(p).type">
                                            <option v-for="(label, k) in SUGG_TYPES" :key="k" :value="k">{{ label }}</option>
                                        </select>
                                        <select v-if="sugg(p).type === 'statut'" class="form-select form-select-sm" style="width:auto" v-model="sugg(p).statut">
                                            <option v-for="(label, k) in SUGG_STATUTS" :key="k" :value="k">{{ label }}</option>
                                        </select>
                                        <template v-if="sugg(p).type === 'date_debut'">
                                            <input type="number" min="1990" max="2040" placeholder="Début" class="form-control form-control-sm" style="width:90px" v-model="sugg(p).annee_debut" />
                                            <input type="number" min="1990" max="2040" placeholder="Fin" class="form-control form-control-sm" style="width:80px" v-model="sugg(p).annee_fin" />
                                            <input type="text" placeholder="Source (optionnel)" class="form-control form-control-sm" v-model="sugg(p).source" />
                                        </template>
                                        <input v-if="sugg(p).type === 'note'" type="text" placeholder="Note" class="form-control form-control-sm" v-model="sugg(p).note" />
                                        <input v-if="sugg(p).type === 'lien'" type="text" placeholder="Lien trouvé" class="form-control form-control-sm" v-model="sugg(p).lien" />
                                        <input v-if="sugg(p).type === 'autre'" type="text" placeholder="Décrivez la correction" class="form-control form-control-sm" style="min-width:160px" v-model="sugg(p).texte" />
                                        <button class="btn btn-sm btn-success" :disabled="sugg(p).sending" @click="sendSuggestion(p)">
                                            {{ sugg(p).sending ? '…' : 'Envoyer' }}
                                        </button>
                                    </div>
                                    <input type="text" placeholder="Commentaire pour la modération (optionnel)" class="form-control form-control-sm mt-1" v-model="sugg(p).commentaire" />
                                    <div v-if="sugg(p).message" class="small mt-1" :class="sugg(p).ok ? 'text-success' : 'text-danger'">{{ sugg(p).message }}</div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="filtered.length === 0" class="text-center text-body-secondary py-5">Aucun projet ne correspond aux filtres.</div>

            <div v-if="candidats.length" class="mt-5">
                <h2 class="h6">Candidats Fonds vert non rattachés ({{ candidats.length }})</h2>
                <p class="small text-muted">
                    Lignes « Atlas de la biodiversité communale » des listes Fonds vert (P113) qui ne
                    correspondent à aucun projet connu : probable doublon sous une autre appellation, ou
                    nouvel ABC financé pas encore recensé par l'OFB. Aucun projet n'est créé sans
                    vérification humaine — <Link href="/methodologie">voir la méthodologie</Link>.
                </p>
                <div class="table-responsive bg-white border rounded-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Projet financé</th>
                                <th>Bénéficiaire</th>
                                <th>Millésime</th>
                                <th class="text-end">Montant engagé</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in candidats" :key="c.ligne_id">
                                <td>
                                    <span class="nom">{{ c.nom }}</span>
                                    <span v-if="c.ambigu && c.ambigu.length" class="sub d-block">
                                        Plusieurs projets existants portent un nom proche : à départager.
                                    </span>
                                </td>
                                <td class="small">{{ c.beneficiaire || '—' }}</td>
                                <td class="small">{{ c.annee || '—' }}</td>
                                <td class="small text-end">{{ fmtMontant(c.montant) || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="foot">
                <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Une information erronée ou non vérifiée ? Les membres inscrits
                peuvent suggérer une correction : elle sera étudiée par un administrateur avant d'être appliquée.
                Votre adresse IP est conservée uniquement à des fins de modération (RGPD).
            </p>
        </div>
    </Layout>
</template>

<style>
.badge-cell { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #e2e8f0; color: #334155; white-space: nowrap; }
.badge-cell.termine { background: #e3f2fd; color: #1565c0; }
.badge-cell.en_cours { background: #fff3e0; color: #b45309; }
.badge-cell.archives { background: #fde8e8; color: #7f1d1d; }
.badge-cell.anomalie { background: #fde8e8; color: #b91c1c; }
.badge-cell.confirme_termine { background: #e8f5e9; color: #1b5e20; }
.badge-cell.confirme_en_cours { background: #fff3e0; color: #b45309; }
.badge-cell.toujours_a_avenir { background: #fef9c3; color: #854d0e; }
.badge-cell.introuvable { background: #e2e8f0; color: #475569; }
.badge-cell.douteux { background: #fde8e8; color: #7f1d1d; }
.nom { font-weight: 600; }
.sub { color: #64748b; font-size: 12px; }
.communes { max-width: 240px; white-space: normal; word-break: break-word; }
.form { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
.form input[type=text] { width: 110px; }
.saved { font-size: 11px; color: #1b5e20; white-space: nowrap; }
.foot { font-size: 12px; color: #64748b; margin-top: 16px; line-height: 1.6; }
</style>
