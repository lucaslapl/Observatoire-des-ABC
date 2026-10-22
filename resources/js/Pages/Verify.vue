<script setup>
import { ref, computed, reactive, onMounted, nextTick } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
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
    toujours_a_venir: { label: 'Toujours à venir', cls: 'toujours_a_venir' },
    confirme_date: { label: '✓ Date(s) vérifiée(s)', cls: 'confirme_termine' },
    introuvable: { label: 'Introuvable', cls: 'introuvable' },
    douteux: { label: 'Incertain', cls: 'douteux' },
};
const ETAT_HELP = {
    a_verifier: "Remet le projet dans la liste à vérifier (annule le verdict actuel).",
    confirme_termine: "L'ABC est terminé : le projet a abouti et n'est plus en cours de réalisation.",
    confirme_en_cours: "L'ABC est en cours de réalisation.",
    toujours_a_venir: "Le projet n'a pas encore débuté, malgré une date annoncée antérieure.",
    confirme_date: "Corrige ou confirme les années du projet. L'année de début seule suffit si c'est tout ce que vous avez pu vérifier ; l'année de fin est optionnelle. Par défaut le statut reste celui calculé automatiquement : s'il est erroné, sélectionnez le statut constaté.",
    introuvable: "Aucune trace du projet malgré les recherches (page disparue, bénéficiaire injoignable…).",
    douteux: "Sources contradictoires ou trop anciennes : le projet est signalé comme incertain.",
};
// Les motifs renvoyés par l'API (contrat parité, cf. GeoJsonParityTest)
// sont des libellés français ; on n'a ici que leur classe CSS.
const MOTIF_CLS = {
    'potentiellement terminé': 'termine',
    'potentiellement en cours': 'en_cours',
    'archives 2022': 'archives',
    'anomalie': 'anomalie',
};

const SUGG_TYPES = {
    statut: 'Statut du projet',
    date_debut: 'Années de début / fin',
    note: 'Note',
    lien: 'Lien (URL)',
    autre: 'Autre information',
};
const SUGG_HELP = {
    statut: "Le statut affiché est inexact : indiquez celui que vous avez constaté (terminé, en cours, à venir…).",
    date_debut: "Corrigez les années de l'ABC. L'année de début seule suffit si c'est tout ce que vous avez pu confirmer ; l'année de fin, le statut et la source sont optionnels. Précisez le statut si le recalcul automatique est faux (ex. ABC débuté en cours d'année encore affiché « à venir »).",
    note: 'Proposez la note qui devrait remplacer celle affichée.',
    lien: "Indiquez l'URL de la page officielle du projet (actuelle ou plus récente).",
    autre: 'Décrivez la correction à apporter (nom, structure porteuse, communes…).',
};
const SUGG_STATUTS = { termine: 'Terminé', en_cours: 'En cours', va_debuter: 'À venir' };
// Statut constaté choisi avec un verdict « confirme_date » → verdict de statut
// enregistré (même mapping que le backend, cf. StatusService).
const ETAT_POUR_STATUT = { termine: 'confirme_termine', en_cours: 'confirme_en_cours', va_debuter: 'toujours_a_venir' };

const sMotif = ref('');
const sEtat = ref('a_verifier');
const sQ = ref('');

const forms = reactive({});
const form = (p) => forms[p.id] || (forms[p.id] = { etat: p.etat, statut: '', note: p.note || '', lien: p.lien || '', annee_debut: p.annee_debut || '', annee_fin: p.annee_fin || '' });

const suggs = reactive({});
const sugg = (p) => suggs[p.id] || (suggs[p.id] = {
    type: 'statut', statut: 'termine', statut_date: '', annee_debut: '', annee_fin: '', source: '',
    note: '', lien: '', texte: '', commentaire: '', sending: false, message: '', ok: false,
});

// Modal de suggestion : un seul projet à la fois, l'utilisateur se concentre
// sur la correction qu'il veut apporter.
const suggOuvert = ref(null);
const ouvrirSuggestion = (p) => { suggOuvert.value = p; };
const fermerSuggestion = () => { suggOuvert.value = null; };
const suggActif = computed(() => suggOuvert.value ? sugg(suggOuvert.value) : null);

// Arrivée depuis la carte (popup « Signaler une correction ») : le projet
// ciblé est passé en paramètre d'URL — on affiche sa ligne et, si
// l'utilisateur connecté a le droit de contribuer, on ouvre la modale.
onMounted(async () => {
    const pid = new URLSearchParams(window.location.search).get('projet');
    const p = pid ? props.projets.find((x) => x.id === pid) : null;
    if (!p) return;
    sMotif.value = '';
    sEtat.value = '';
    sQ.value = '';
    await nextTick();
    document.querySelector(`tr[data-projet-id="${CSS.escape(p.id)}"]`)
        ?.scrollIntoView({ block: 'center' });
    if (user.value && user.value.email_verifie) ouvrirSuggestion(p);
});

// Modal de verdict (admin / modérateur), même principe.
const verdictOuvert = ref(null);
const ouvrirVerdict = (p) => { verdictOuvert.value = p; };
const fermerVerdict = () => { verdictOuvert.value = null; };
const formActif = computed(() => verdictOuvert.value ? form(verdictOuvert.value) : null);

// Fusion d'un doublon suspect dans un projet canonique (admin uniquement).
const fusion = reactive({
    ouvert: false, projet: null, q: '', resultats: [], canoniqueId: '',
    motif: '', sending: false, message: '', ok: false,
});
const ouvrirFusion = (p) => {
    fusion.ouvert = true;
    fusion.projet = p;
    fusion.q = '';
    fusion.resultats = [];
    fusion.canoniqueId = '';
    fusion.motif = '';
    fusion.message = '';
    fusion.ok = false;
    chercherProjets('');
};
const fermerFusion = () => { fusion.ouvert = false; fusion.projet = null; };

// Tranchage « ce n'est pas un doublon » (admin) : la note automatique
// « Doublon suspect » est retirée pour tout le groupe d'homonymes et la
// décision est persistée — le collect ne la recréera pas.
const nonDoublon = reactive({
    ouvert: false, projet: null, motif: '', sending: false, message: '', ok: false,
});
const ouvrirNonDoublon = (p) => {
    nonDoublon.ouvert = true;
    nonDoublon.projet = p;
    nonDoublon.motif = '';
    nonDoublon.message = '';
    nonDoublon.ok = false;
};
const fermerNonDoublon = () => { nonDoublon.ouvert = false; nonDoublon.projet = null; };

const confirmerNonDoublon = async () => {
    if (!nonDoublon.projet || nonDoublon.sending) return;
    nonDoublon.message = '';
    nonDoublon.ok = false;
    nonDoublon.sending = true;
    try {
        const r = await axios.post(`/api/admin/projets/${encodeURIComponent(nonDoublon.projet.id)}/non-doublon`, {
            motif: nonDoublon.motif || null,
        });
        fermerNonDoublon();
        fache.type = 'success';
        fache.message = `Marqué non-doublon : ${r.data.notes_retirees ?? 0} note(s) « Doublon suspect » retirée(s)`
            + ` — le collect ne les recréera pas.`;
        router.reload({ only: ['projets', 'compteurs'] });
    } catch (err) {
        nonDoublon.ok = false;
        nonDoublon.message = 'Erreur : ' + (err.response?.data?.error || err.message);
    } finally {
        nonDoublon.sending = false;
    }
};

let fusionTimer = null;
const chercherProjets = (q) => {
    if (fusionTimer) clearTimeout(fusionTimer);
    fusionTimer = setTimeout(async () => {
        try {
            const r = await axios.get('/api/admin/projets/recherche', { params: { q } });
            fusion.resultats = r.data.projets || [];
        } catch {
            fusion.resultats = [];
        }
    }, 250);
};

const fusionner = async () => {
    if (!fusion.projet || !fusion.canoniqueId) return;
    fusion.message = '';
    fusion.ok = false;
    fusion.sending = true;
    try {
        const r = await axios.post(`/api/admin/projets/${encodeURIComponent(fusion.projet.id)}/fusion`, {
            canonique_id: fusion.canoniqueId,
            motif: fusion.motif || null,
        });
        fusion.ok = true;
        fusion.message = `Fusion effectuée : ${r.data.stats?.communes_deplacees ?? 0} commune(s) unie(s), `
            + `${r.data.stats?.financements_deplaces ?? 0} financement(s) migré(s).`;
        fermerFusion();
        fache.type = 'success';
        fache.message = 'Doublon fusionné dans le projet canonique (alias créé, redirection 301, audit journalisé).';
        router.reload({ only: ['projets', 'compteurs'] });
    } catch (err) {
        fusion.ok = false;
        fusion.message = 'Erreur : ' + (err.response?.data?.error || err.message);
    } finally {
        fusion.sending = false;
    }
};

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
    return (c.confirme_termine || 0) + (c.confirme_en_cours || 0) + (c.toujours_a_venir || 0) + (c.confirme_date || 0) + (c.introuvable || 0) + (c.douteux || 0);
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
        if (f.statut) body.statut_suggere = f.statut;
    }
    try {
        await axios.post('/api/verifications', body);
        const target = props.projets.find((x) => x.id === p.id);
        // Avec un statut constaté, le backend enregistre le verdict de statut
        // correspondant au lieu de « confirme_date ».
        target.etat = (f.etat === 'confirme_date' && f.statut) ? ETAT_POUR_STATUT[f.statut] : f.etat;
        target.note = f.note;
        target.verifie_le = new Date().toISOString();
        target.verifie_par = user.value?.name || null;
        if (f.etat === 'confirme_date' && (Number(f.annee_debut) || Number(f.annee_fin))) {
            target.annee_debut = Number(f.annee_debut) || null;
            target.annee_fin = Number(f.annee_fin) || null;
        }
        fache.type = 'success';
        fache.message = 'Vérification enregistrée.';
        fermerVerdict();
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
        if (s.statut_date) body.statut_suggere = s.statut_date;
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
        const resp = await axios.post('/api/contributions', body);
        s.ok = true;
        s.commentaire = '';
        if (resp.data?.auto_validee) {
            s.message = 'Correction appliquée immédiatement (admin / modérateur).';
            fermerSuggestion();
            fache.type = 'success';
            fache.message = 'Correction appliquée au projet.';
            router.reload({ only: ['projets', 'compteurs'] });
        } else {
            s.message = 'Merci, suggestion envoyée : elle sera examinée par un administrateur avant application.';
        }
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
                        <option value="toujours_a_venir">Toujours à venir</option>
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
                    <span v-if="compteurs.toujours_a_venir" class="badge text-bg-warning">{{ compteurs.toujours_a_venir }} à venir</span>
                    <span v-if="compteurs.confirme_date" class="badge text-bg-info">{{ compteurs.confirme_date }} dates vérifiées</span>
                    <span v-if="compteurs.introuvable" class="badge text-bg-secondary">{{ compteurs.introuvable }} introuvables</span>
                    <span v-if="compteurs.douteux" class="badge text-bg-danger">{{ compteurs.douteux }} incertains</span>
                </div>
            </div>

            <div class="small text-muted mb-2">
                Les projets déjà vérifiés restent consultables : passez le filtre
                « État » sur <b>Vérifiés</b> ou <b>Tous</b>. Une erreur sur un projet confirmé ?
                <template v-if="peutTrancher">Rouvrez « Modifier le verdict » pour statuer sur le projet, ou « Appliquer une correction » pour corriger une information sans verdict complet.</template>
                <template v-else-if="isContributeur">Utilisez le bouton « Suggérer une correction » de la ligne concernée — le lien « Signaler une correction » des popups de la carte vous amène directement ici, sur le projet visé.</template>
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
                            <th v-if="peutTrancher">Verdict</th>
                            <th v-if="user">Suggestion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in filtered" :key="p.id" :class="rowClass(p)" :data-projet-id="p.id">
                            <td>
                                <div class="nom">{{ p.nom }}</div>
                                <div class="sub">{{ p.structure_porteuse || '—' }}{{ p.departements ? ' · ' + p.departements : '' }}</div>
                                <div v-if="p.note_auto" class="small text-muted mt-1" :title="p.note_auto">
                                    <div v-for="(ligne, i) in p.note_auto.split('\n')" :key="i">{{ ligne }}</div>
                                </div>
                                <div v-if="isAdmin && p.note_auto && p.note_auto.includes('Doublon suspect')" class="d-flex gap-1 flex-wrap mt-1">
                                    <button
                                        type="button" class="btn btn-sm btn-outline-danger"
                                        @click="ouvrirFusion(p)"
                                    >
                                        <i class="bi bi-union me-1" aria-hidden="true"></i>Fusionner ce doublon
                                    </button>
                                    <button
                                        type="button" class="btn btn-sm btn-outline-secondary"
                                        title="Les projets homonymes sont des ABC distincts : la note automatique disparaît et ne sera pas recréée"
                                        @click="ouvrirNonDoublon(p)"
                                    >
                                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Ce n'est pas un doublon
                                    </button>
                                </div>
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
                                <button type="button" class="btn btn-sm" :class="p.etat === 'a_verifier' ? 'btn-outline-primary' : 'btn-outline-secondary'" @click="ouvrirVerdict(p)">
                                    <i class="bi bi-check2-square me-1" aria-hidden="true"></i>{{ p.etat === 'a_verifier' ? 'Rendre un verdict' : 'Modifier le verdict' }}
                                </button>
                                <div v-if="p.etat !== 'a_verifier' && p.verifie_le" class="saved">
                                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ formatDate(p.verifie_le) }}<template v-if="p.verifie_par"> · {{ p.verifie_par }}</template>
                                </div>
                            </td>
                            <td v-if="user">
                                <template v-if="!user.email_verifie">
                                    <div class="small text-muted">
                                        Vérifiez votre adresse email (lien reçu à l'inscription) pour pouvoir envoyer une suggestion.
                                    </div>
                                </template>
                                <button v-else type="button" class="btn btn-sm btn-outline-success" @click="ouvrirSuggestion(p)">
                                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>{{ peutTrancher ? 'Appliquer une correction' : 'Suggérer une correction' }}
                                </button>
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

            <div v-if="fusion.ouvert" class="sugg-backdrop" @click.self="fermerFusion">
                <div class="sugg-modal" role="dialog" aria-modal="true" aria-labelledby="fusion-titre" tabindex="-1" @keydown.esc="fermerFusion">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 id="fusion-titre" class="h6 mb-1">Fusionner un doublon</h2>
                            <div class="small text-muted">
                                <span class="nom">{{ fusion.projet?.nom }}</span>
                                <span class="text-danger"> — sera fusionné dans le projet canonique choisi ci-dessous</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Fermer" @click="fermerFusion"></button>
                    </div>

                    <p class="small text-muted mb-3">
                        À n'utiliser que si les deux projets décrivent le <b>même ABC réel</b>.
                        Le doublon devient un alias du canonique : communes, financements, contributions et
                        vérifications migrent sans perte, son slug redirige en 301, et tout est journalisé
                        (audit + snapshot de rollback). Si ce sont deux ABC distincts (même nom, territoires
                        différents), fermez cette fenêtre : rendez simplement un verdict et ignorez la note
                        automatique.
                    </p>

                    <label class="form-label small mb-1">Projet canonique <span class="text-danger">*</span></label>
                    <input
                        type="text" class="form-control mb-2" placeholder="Rechercher par nom ou structure…"
                        v-model="fusion.q" @input="chercherProjets(fusion.q)"
                    />
                    <div class="mb-3" style="max-height: 240px; overflow-y: auto;">
                        <label
                            v-for="c in fusion.resultats.filter((x) => x.id !== fusion.projet?.id)"
                            :key="c.id" class="sugg-projet"
                            :class="{ actif: fusion.canoniqueId === c.id }"
                        >
                            <input type="radio" class="form-check-input m-0" :value="c.id" v-model="fusion.canoniqueId" />
                            <span>
                                <b>{{ c.nom }}</b>
                                <span class="small text-muted"> · {{ c.structure_porteuse || '—' }}{{ c.annee_debut ? ' · ' + c.annee_debut : '' }}</span>
                                <span class="small text-muted d-block">{{ c.id }}</span>
                            </span>
                        </label>
                        <div v-if="fusion.resultats.length === 0" class="small text-muted">Aucun projet ne correspond.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1">Motif <span class="text-muted">(journalisé, optionnel)</span></label>
                        <input type="text" class="form-control" placeholder="Ex. même ABC décrit sous deux noms dans deux sources" v-model="fusion.motif" />
                    </div>

                    <div v-if="fusion.message" class="small mb-2" :class="fusion.ok ? 'text-success' : 'text-danger'">{{ fusion.message }}</div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="fermerFusion">Fermer</button>
                        <button type="button" class="btn btn-sm btn-danger" :disabled="fusion.sending || !fusion.canoniqueId" @click="fusionner">
                            {{ fusion.sending ? 'Fusion…' : 'Fusionner' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="nonDoublon.ouvert" class="sugg-backdrop" @click.self="fermerNonDoublon">
                <div class="sugg-modal" role="dialog" aria-modal="true" aria-labelledby="non-doublon-titre" tabindex="-1" @keydown.esc="fermerNonDoublon">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 id="non-doublon-titre" class="h6 mb-1">Ce n'est pas un doublon</h2>
                            <div class="small text-muted">
                                <span class="nom">{{ nonDoublon.projet?.nom }}</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Fermer" @click="fermerNonDoublon"></button>
                    </div>

                    <p class="small text-muted mb-3">
                        À n'utiliser que si les projets portant ce nom sont des <b>ABC distincts</b>
                        (même nom, territoires différents). La note automatique « Doublon suspect »
                        disparaît pour tous les homonymes et ne sera pas recréée au prochain collect :
                        cette décision est journalisée et prime sur le signalement automatique.
                        Si c'est en réalité le même ABC, fermez cette fenêtre et utilisez plutôt
                        « Fusionner ce doublon ».
                    </p>

                    <div class="mb-3">
                        <label class="form-label small mb-1">Motif <span class="text-muted">(journalisé, optionnel)</span></label>
                        <input type="text" class="form-control" placeholder="Ex. deux ABC distincts, territoires différents" v-model="nonDoublon.motif" />
                    </div>

                    <div v-if="nonDoublon.message" class="small mb-2" :class="nonDoublon.ok ? 'text-success' : 'text-danger'">{{ nonDoublon.message }}</div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="fermerNonDoublon">Fermer</button>
                        <button type="button" class="btn btn-sm btn-secondary" :disabled="nonDoublon.sending" @click="confirmerNonDoublon">
                            {{ nonDoublon.sending ? 'Application…' : 'Confirmer : ce n\'est pas un doublon' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="verdictOuvert" class="sugg-backdrop" @click.self="fermerVerdict">
                <div class="sugg-modal" role="dialog" aria-modal="true" aria-labelledby="verdict-titre" tabindex="-1" @keydown.esc="fermerVerdict">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 id="verdict-titre" class="h6 mb-1">{{ verdictOuvert.etat === 'a_verifier' ? 'Rendre un verdict' : 'Modifier le verdict' }}</h2>
                            <div class="small text-muted">
                                <span class="nom">{{ verdictOuvert.nom }}</span>
                                <span v-if="verdictOuvert.structure_porteuse"> · {{ verdictOuvert.structure_porteuse }}</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Fermer" @click="fermerVerdict"></button>
                    </div>

                    <p class="small text-muted mb-3">
                        Ce verdict statue sur le projet à vérifier : ce qu'il est réellement (terminé, en cours,
                        dates…). Il est appliqué immédiatement et affiché sur la fiche et la carte.
                        La modération des suggestions envoyées par les contributeurs se fait séparément,
                        dans le panneau d'administration. Précisez si possible un lien source qui étaye le verdict.
                    </p>

                    <div class="mb-2">
                        <label class="form-label small mb-1">Verdict</label>
                        <select class="form-select" v-model="formActif.etat">
                            <option v-for="(v, k) in ETATS" :key="k" :value="k">{{ v.label }}</option>
                        </select>
                    </div>
                    <div class="small text-muted mb-3">{{ ETAT_HELP[formActif.etat] }}</div>

                    <template v-if="formActif.etat === 'confirme_date'">
                        <div class="d-flex gap-2 mb-3">
                            <div class="flex-fill">
                                <label class="form-label small mb-1">Année de début</label>
                                <input type="number" min="1990" max="2040" class="form-control" placeholder="Ex. 2023" v-model="formActif.annee_debut" />
                            </div>
                            <div class="flex-fill">
                                <label class="form-label small mb-1">Année de fin <span class="text-muted">(optionnelle)</span></label>
                                <input type="number" min="1990" max="2040" class="form-control" v-model="formActif.annee_fin" />
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1">Statut constaté <span class="text-muted">(optionnel, si le statut calculé est erroné)</span></label>
                            <select class="form-select" v-model="formActif.statut">
                                <option value="">Laisser le statut calculé automatiquement</option>
                                <option v-for="(label, k) in SUGG_STATUTS" :key="k" :value="k">{{ label }}</option>
                            </select>
                            <div v-if="formActif.statut" class="small text-muted mt-1">
                                Le verdict enregistré sera « {{ ETATS[ETAT_POUR_STATUT[formActif.statut]].label }} » : les dates sont corrigées et le statut affiché sur la fiche et la carte devient celui constaté.
                            </div>
                        </div>
                    </template>

                    <div class="mb-3">
                        <label class="form-label small mb-1">Note <span class="text-muted">(optionnelle)</span></label>
                        <input type="text" class="form-control" placeholder="Contexte du verdict" v-model="formActif.note" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Lien source <span class="text-muted">(optionnel)</span></label>
                        <input type="text" class="form-control" placeholder="https://…" v-model="formActif.lien" />
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="fermerVerdict">Fermer</button>
                        <button type="button" class="btn btn-sm btn-success" @click="save(verdictOuvert, formActif)">
                            Enregistrer le verdict
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="suggOuvert" class="sugg-backdrop" @click.self="fermerSuggestion">
                <div class="sugg-modal" role="dialog" aria-modal="true" aria-labelledby="sugg-titre" tabindex="-1" @keydown.esc="fermerSuggestion">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 id="sugg-titre" class="h6 mb-1">{{ peutTrancher ? 'Appliquer une correction' : 'Suggérer une correction' }}</h2>
                            <div class="small text-muted">
                                <span class="nom">{{ suggOuvert.nom }}</span>
                                <span v-if="suggOuvert.structure_porteuse"> · {{ suggOuvert.structure_porteuse }}</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Fermer" @click="fermerSuggestion"></button>
                    </div>

                    <p class="small text-muted mb-3">
                        Choisissez le type de correction, puis détaillez-la.
                        <template v-if="peutTrancher">
                            En tant qu'administrateur ou modérateur, elle sera appliquée immédiatement au projet,
                            avec trace d'audit (réversible depuis le panneau d'administration).
                        </template>
                        <template v-else>
                            La suggestion sera examinée par un administrateur avant d'être appliquée.
                        </template>
                    </p>

                    <div class="mb-2">
                        <div class="row g-2">
                            <div v-for="(label, k) in SUGG_TYPES" :key="k" class="col-6 col-lg-4">
                                <label class="sugg-type" :class="{ actif: suggActif.type === k }">
                                    <input type="radio" class="d-none" :value="k" v-model="suggActif.type" />
                                    {{ label }}
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="small text-muted mb-3">{{ SUGG_HELP[suggActif.type] }}</div>

                    <div v-if="suggActif.type === 'statut'" class="mb-3">
                        <label class="form-label small mb-1">Statut constaté</label>
                        <select class="form-select" v-model="suggActif.statut">
                            <option v-for="(label, k) in SUGG_STATUTS" :key="k" :value="k">{{ label }}</option>
                        </select>
                    </div>
                    <template v-if="suggActif.type === 'date_debut'">
                        <div class="d-flex gap-2 mb-3">
                            <div class="flex-fill">
                                <label class="form-label small mb-1">Année de début <span class="text-danger">*</span></label>
                                <input type="number" min="1990" max="2040" class="form-control" placeholder="Ex. 2023" v-model="suggActif.annee_debut" />
                            </div>
                            <div class="flex-fill">
                                <label class="form-label small mb-1">Année de fin <span class="text-muted">(optionnelle)</span></label>
                                <input type="number" min="1990" max="2040" class="form-control" v-model="suggActif.annee_fin" />
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1">Source <span class="text-muted">(page confirmant les dates, optionnelle)</span></label>
                            <input type="text" class="form-control" placeholder="https://…" v-model="suggActif.source" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1">Statut constaté <span class="text-muted">(optionnel)</span></label>
                            <select class="form-select" v-model="suggActif.statut_date">
                                <option value="">Laisser le statut calculé automatiquement</option>
                                <option v-for="(label, k) in SUGG_STATUTS" :key="k" :value="k">{{ label }}</option>
                            </select>
                        </div>
                    </template>
                    <div v-if="suggActif.type === 'note'" class="mb-3">
                        <label class="form-label small mb-1">Note proposée</label>
                        <textarea class="form-control" rows="3" placeholder="Texte qui devrait remplacer la note actuelle" v-model="suggActif.note"></textarea>
                    </div>
                    <div v-if="suggActif.type === 'lien'" class="mb-3">
                        <label class="form-label small mb-1">URL du projet</label>
                        <input type="text" class="form-control" placeholder="https://…" v-model="suggActif.lien" />
                    </div>
                    <div v-if="suggActif.type === 'autre'" class="mb-3">
                        <label class="form-label small mb-1">Correction à apporter <span class="text-danger">*</span></label>
                        <textarea class="form-control" rows="3" placeholder="Décrivez la correction (nom, structure porteuse, communes…)" v-model="suggActif.texte"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1">Commentaire pour la modération <span class="text-muted">(optionnel)</span></label>
                        <input type="text" class="form-control" placeholder="Contexte utile à l'administrateur qui examinera la suggestion" v-model="suggActif.commentaire" />
                    </div>

                    <div v-if="suggActif.message" class="small mb-2" :class="suggActif.ok ? 'text-success' : 'text-danger'">{{ suggActif.message }}</div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="fermerSuggestion">Fermer</button>
                        <button type="button" class="btn btn-sm btn-success" :disabled="suggActif.sending" @click="sendSuggestion(suggOuvert)">
                            {{ suggActif.sending ? 'Envoi…' : (peutTrancher ? 'Appliquer la correction' : 'Envoyer la suggestion') }}
                        </button>
                    </div>
                </div>
            </div>
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
.badge-cell.toujours_a_venir { background: #fef9c3; color: #854d0e; }
.badge-cell.introuvable { background: #e2e8f0; color: #475569; }
.badge-cell.douteux { background: #fde8e8; color: #7f1d1d; }
.nom { font-weight: 600; }
.sub { color: #64748b; font-size: 12px; }
.communes { max-width: 240px; white-space: normal; word-break: break-word; }
.saved { font-size: 11px; color: #1b5e20; white-space: nowrap; }
.sugg-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 1080; display: flex; align-items: flex-start; justify-content: center; padding: 3rem 1rem; overflow-y: auto; }
.sugg-modal { background: #fff; border-radius: 0.75rem; box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.25); max-width: 580px; width: 100%; padding: 1.25rem; }
.sugg-type { display: block; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-size: 13px; cursor: pointer; }
.sugg-type:hover { border-color: #14532d; }
.sugg-type.actif { border-color: #14532d; background: #f0fdf4; color: #14532d; font-weight: 600; }
.sugg-projet { display: flex; align-items: flex-start; gap: 8px; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-size: 13px; cursor: pointer; margin-bottom: 4px; }
.sugg-projet:hover { border-color: #14532d; }
.sugg-projet.actif { border-color: #14532d; background: #f0fdf4; }
.foot { font-size: 12px; color: #64748b; margin-top: 16px; line-height: 1.6; }
</style>
