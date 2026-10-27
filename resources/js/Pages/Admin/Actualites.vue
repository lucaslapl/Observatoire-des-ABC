<script setup>
import { reactive, ref, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';
import Pagination from '../../Components/Admin/Pagination.vue';

defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    actualites: { type: Object, required: true },
});

const busy = ref(false);
const actualiteMsg = ref('');
const actualiteOk = ref(true);

const actualiteForm = reactive({
    id: null,
    titre: '',
    contenu: '',
    statut: 'publie',
    date_publication: '',
});

// --- Éditeur Markdown ---
// Barre d'outils insérant la syntaxe autour de la sélection, et aperçu
// rendu par le serveur (POST /actualites/preview) : même rendu que la
// page publique, sans bibliothèque Markdown côté client.
const contenuTextarea = ref(null);
const showPreview = ref(false);
const previewHtml = ref('');
const previewBusy = ref(false);
let previewTimer = null;
let previewSeq = 0;

function focusContenu() {
    nextTick(() => contenuTextarea.value?.focus())
}

/** Entoure la sélection de balises Markdown (`**gras**`, `[lien](url)`…). */
function wrapSelection(before, after = before, placeholder = 'texte') {
    const el = contenuTextarea.value;
    const value = actualiteForm.contenu;
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? start;
    const selected = value.slice(start, end) || placeholder;
    actualiteForm.contenu = value.slice(0, start) + before + selected + after + value.slice(end);
    const caret = start + before.length;
    nextTick(() => {
        if (!el) return;
        el.focus();
        el.selectionStart = caret;
        el.selectionEnd = caret + selected.length;
    });
}

/** Préfixe chaque ligne de la sélection (`## Titre`, `- item`, `> citation`…). */
function prefixLines(prefix) {
    const el = contenuTextarea.value;
    const value = actualiteForm.contenu;
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? start;
    const lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
    const lineEnd = end + (value.slice(end).indexOf('\n') === -1 ? 0 : value.slice(end).indexOf('\n'));
    const block = value.slice(lineStart, lineEnd);
    const prefixed = block
        .split('\n')
        .map((l) => (l.startsWith(prefix) ? l : prefix + l))
        .join('\n');
    actualiteForm.contenu = value.slice(0, lineStart) + prefixed + value.slice(lineEnd);
    focusContenu();
}

async function refreshPreview() {
    const contenu = actualiteForm.contenu;
    const seq = ++previewSeq;
    previewBusy.value = true;
    try {
        const r = await axios.post('/actualites/preview', { contenu });
        if (seq === previewSeq) previewHtml.value = r.data.html;
    } catch {
        // Silencieux : l'aperçu reprendra au prochain arrêt de frappe.
    } finally {
        if (seq === previewSeq) previewBusy.value = false;
    }
}

watch(
    () => [showPreview.value, actualiteForm.contenu],
    () => {
        if (!showPreview.value) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 300);
    },
);

const formatDateFr = (d) => (d ? new Date(d).toLocaleDateString('fr-FR') : '');

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

function openActualite(a) {
    actualiteForm.id = a ? a.id : null;
    actualiteForm.titre = a ? a.titre : '';
    actualiteForm.contenu = a ? a.contenu : '';
    actualiteForm.statut = a ? a.statut : 'publie';
    actualiteForm.date_publication = a && a.date_publication ? a.date_publication.slice(0, 10) : '';
    showPreview.value = false;
    previewHtml.value = '';
}

async function saveActualite() {
    if (!actualiteForm.titre.trim()) {
        actualiteMsg.value = 'Le titre est requis.';
        actualiteOk.value = false;
        return;
    }
    if (!actualiteForm.contenu.trim()) {
        actualiteMsg.value = 'Le contenu est requis.';
        actualiteOk.value = false;
        return;
    }
    busy.value = true;
    actualiteMsg.value = '';
    const payload = {
        titre: actualiteForm.titre,
        contenu: actualiteForm.contenu,
        statut: actualiteForm.statut,
        date_publication: actualiteForm.date_publication || null,
    };
    try {
        if (actualiteForm.id) {
            await router.put(`/actualites/${actualiteForm.id}`, payload);
        } else {
            await router.post('/actualites', payload);
        }
        actualiteMsg.value = 'Actualité enregistrée.';
        actualiteOk.value = true;
        openActualite(null);
    } catch (e) {
        actualiteMsg.value = 'Erreur : ' + errMsg(e);
        actualiteOk.value = false;
    } finally {
        busy.value = false;
    }
}

async function destroyActualite(a) {
    if (!window.confirm(`Supprimer l'actualité « ${a.titre} » ?`)) return;
    busy.value = true;
    actualiteMsg.value = '';
    try {
        await router.delete(`/actualites/${a.id}`);
        actualiteMsg.value = 'Actualité supprimée.';
        actualiteOk.value = true;
    } catch (e) {
        actualiteMsg.value = 'Erreur : ' + errMsg(e);
        actualiteOk.value = false;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — actualités" description="Gestion des actualités de l'Observatoire des ABC." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h3 mb-0">Actualités</h1>
            <span class="text-muted">rédaction et publication</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
                <span class="fw-semibold">{{ actualiteForm.id ? 'Modifier l\u2019actualité' : 'Nouvelle actualité' }}</span>
                <button v-if="actualiteForm.id" class="btn btn-sm btn-outline-secondary ms-auto" @click="openActualite(null)"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Annuler l'édition</button>
            </div>
            <div class="card-body py-2">
                <form @submit.prevent="saveActualite" class="mb-2">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <input v-model="actualiteForm.titre" type="text" class="form-control form-control-sm" style="max-width:280px"
                            :placeholder="actualiteForm.id ? 'Titre (en cours d\u2019édition)' : 'Titre'" />
                        <select v-model="actualiteForm.statut" class="form-select form-select-sm" style="max-width:130px">
                            <option value="publie">Publié</option>
                            <option value="masque">Masqué</option>
                        </select>
                        <input v-model="actualiteForm.date_publication" type="date" class="form-control form-control-sm" style="max-width:160px" />
                        <button class="btn btn-xs btn-success btn-sm" type="submit" :disabled="busy">
                            {{ actualiteForm.id ? 'Mettre à jour' : 'Publier' }}
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-2 mb-1" role="toolbar" aria-label="Mise en forme du contenu (Markdown)">
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Titre de section" @click="prefixLines('## ')">Titre</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Sous-titre" @click="prefixLines('### ')">Sous-titre</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Gras" @click="wrapSelection('**')"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Italique" @click="wrapSelection('*')"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Liste à puces" @click="prefixLines('- ')"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Citation" @click="prefixLines('> ')"><i class="bi bi-quote" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Lien" @click="wrapSelection('[', '](https://)', 'libellé du lien')"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Image" @click="wrapSelection('![', '](https://)', 'texte alternatif')"><i class="bi bi-image" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm" :class="showPreview ? 'btn-success' : 'btn-outline-success'" @click="showPreview = !showPreview">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>Aperçu
                        </button>
                    </div>
                    <textarea v-show="!showPreview" ref="contenuTextarea" v-model="actualiteForm.contenu" rows="10" class="form-control form-control-sm mt-2" placeholder="Contenu de l'actualité (Markdown)…"></textarea>
                    <div v-if="showPreview" class="border rounded-3 p-3 mt-2 bg-white actualite-contenu">
                        <div v-if="previewBusy" class="text-muted small mb-2">Rendu en cours…</div>
                        <div v-if="!previewHtml && !previewBusy" class="text-muted">Aperçu vide.</div>
                        <div v-html="previewHtml"></div>
                    </div>
                    <div class="form-text">Markdown : **gras**, *italique*, ## Titre, listes « - », [libellé](url), ![alt](url). Le HTML brut est affiché comme du texte.</div>
                </form>
                <div v-if="actualiteMsg" class="small mb-2" :class="actualiteOk ? 'text-success' : 'text-danger'">{{ actualiteMsg }}</div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">
                Toutes les actualités <span class="text-muted fw-normal">({{ actualites.total }})</span>
            </div>
            <div class="card-body py-2">
                <div v-if="actualites.data.length === 0" class="text-muted small py-2">Aucune actualité.</div>
                <ul v-else class="list-group list-group-flush">
                    <li v-for="a in actualites.data" :key="a.id"
                        class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                        <div class="flex-grow-1" style="min-width:200px">
                            <div class="fw-semibold small">
                                <span class="chip" :class="a.statut">{{ a.statut === 'publie' ? 'publié' : 'masqué' }}</span>
                                &nbsp;{{ a.titre }}
                            </div>
                            <div class="small text-muted">
                                {{ formatDateFr(a.date_publication) }} · {{ a.extrait }}{{ (a.contenu || '').length > 80 ? '…' : '' }}
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="openActualite(a)"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Modifier</button>
                        <button class="btn btn-sm btn-outline-danger" :disabled="busy" @click="destroyActualite(a)"><i class="bi bi-trash me-1" aria-hidden="true"></i>Supprimer</button>
                    </li>
                </ul>
                <Pagination :paginator="actualites" />
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.chip {
    display: inline-block;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #e2e8f0;
    color: #334155;
}
.chip.publie { background: #e8f5e9; color: #1b5e20; }
.chip.masque { background: #fef9c3; color: #854d0e; }
/* Aperçu Markdown : images fluides, citations discrètes (comme en public). */
.actualite-contenu :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.25rem;
}
.actualite-contenu :deep(blockquote) {
    border-left: 3px solid #198754;
    padding-left: 1rem;
    color: #6c757d;
    margin-bottom: 1rem;
}
</style>
