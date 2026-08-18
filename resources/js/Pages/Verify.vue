<script setup>
import { ref, computed, reactive } from 'vue';
import axios from 'axios';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const props = defineProps({
    projets: { type: Array, default: () => [] },
    compteurs: { type: Object, default: () => ({}) },
});

const ETATS = {
    a_verifier: { label: 'À vérifier', cls: '' },
    confirme_termine: { label: '✓ Confirmé terminé', cls: 'confirme_termine' },
    confirme_en_cours: { label: 'Confirmé en cours', cls: 'confirme_en_cours' },
    toujours_a_avenir: { label: 'Toujours à venir', cls: 'toujours_a_avenir' },
    confirme_date: { label: '✓ Date vérifiée', cls: 'confirme_termine' },
    introuvable: { label: 'Introuvable', cls: 'introuvable' },
    douteux: { label: 'Incertain', cls: 'douteux' },
};
const MOTIFS = { termine: 'Potentiellement terminé', en_cours: 'Potentiellement en cours', archives: 'Archives 2022', anomalie: 'Commune incohérente', 'date inconnue': 'Date inconnue' };

const sMotif = ref('');
const sEtat = ref('a_verifier');
const sQ = ref('');

const forms = reactive({});
const form = (p) => forms[p.id] || (forms[p.id] = { etat: p.etat, note: p.note || '', lien: p.lien || '', annee_debut: p.annee_debut || '', annee_fin: p.annee_fin || '' });

const fache = reactive({ type: '', message: '' });

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
    p.motifs.forEach((b) => list.push({ label: MOTIFS[b], cls: b }));
    return list;
};

const formatDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '';

const save = async (p, f) => {
    fache.type = '';
    fache.message = '';
    const body = { projet_id: p.id, etat: f.etat, note: f.note, lien: f.lien };
    if (f.etat === 'confirme_date') {
        const an = Number(f.annee_debut);
        if (an) body.annee_debut = an;
        const fin = Number(f.annee_fin);
        if (fin) body.annee_fin = fin;
    }
    try {
        const r = await axios.post('/api/verifications', body);
        const target = props.projets.find((x) => x.id === p.id);
        target.etat = f.etat;
        target.note = f.note;
        target.verifie_le = new Date().toISOString();
        if (f.etat === 'confirme_date') {
            target.annee_debut = Number(f.annee_debut) || target.annee_debut;
            target.annee_fin = Number(f.annee_fin) || target.annee_fin;
        }
        fache.type = 'success';
        fache.message = 'Vérification enregistrée.';
    } catch (err) {
        fache.type = 'danger';
        if (err.response?.status === 401) {
            fache.message = 'Connexion requise : seuls les administrateurs peuvent enregistrer une vérification.';
            fache.login = true;
        } else {
            fache.message = 'Erreur : ' + (err.response?.data?.error || err.message);
            fache.login = false;
        }
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

            <div v-if="fache.message" class="alert alert-{{ fache.type }} alert-dismissible fade show pb-2 pt-2" role="alert">
                {{ fache.message }}
                <a v-if="fache.login" href="/login" class="btn btn-sm btn-dark ms-2">Se connecter</a>
                <button type="button" class="btn-close" @click="fache.message = ''"></button>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-3 py-2 mb-3 bg-white border rounded-3 px-3">
                <label class="d-flex align-items-center gap-2 me-2" style="font-size:13px; white-space:nowrap;">Motif
                    <select v-model="sMotif" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="termine">Potentiellement terminé</option>
                        <option value="en_cours">Potentiellement en cours</option>
                        <option value="archives">Archives 2022</option>
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
                    <span v-if="verifiesCount" class="badge text-bg-light" title="Projets déjà vérifiés — visibles via le filtre État">✓ {{ verifiesCount }} vérifiés</span>
                    <span v-if="compteurs.confirme_termine" class="badge text-bg-success">{{ compteurs.confirme_termine }} ✓ terminés</span>
                    <span v-if="compteurs.confirme_en_cours" class="badge text-bg-warning">{{ compteurs.confirme_en_cours }} en cours</span>
                    <span v-if="compteurs.toujours_a_avenir" class="badge text-bg-warning">{{ compteurs.toujours_a_avenir }} à venir</span>
                    <span v-if="compteurs.confirme_date" class="badge text-bg-info">{{ compteurs.confirme_date }} dates vérifiées</span>
                    <span v-if="compteurs.introuvable" class="badge text-bg-secondary">{{ compteurs.introuvable }} introuvables</span>
                    <span v-if="compteurs.douteux" class="badge text-bg-danger">{{ compteurs.douteux }} incertains</span>
                </div>
            </div>

            <div class="small text-muted mb-2">
                Les projets déjà vérifiés restent consultables et corrigeables : passez le filtre
                « État » sur <b>Vérifiés</b> ou <b>Tous</b>. Une erreur sur un projet confirmé ?
                Re-sauvegardez un verdict corrigé, ou signalez-la depuis le popup de la carte.
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
                            <th style="min-width:290px">Verdict / Suggestion</th>
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
                                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" :href="searchUrl(p)">🔍 Rechercher</a>
                                <a v-if="p.lien" class="btn btn-sm btn-outline-secondary" :href="p.lien" target="_blank" rel="noopener">🌐 Lien</a>
                            </td>
                            <td>
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
                                    <span v-if="p.etat !== 'a_verifier' && p.verifie_le" class="saved">✓ {{ formatDate(p.verifie_le) }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="filtered.length === 0" class="text-center text-body-secondary py-5">Aucun projet ne correspond aux filtres.</div>
            <p class="foot">
                💡 Une information erronée ou non vérifiée ? Signalez une correction : elle sera
                étudiée par un modérateur avant d'être appliquée. Votre adresse IP est conservée
                uniquement à des fins de modération (RGPD).
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