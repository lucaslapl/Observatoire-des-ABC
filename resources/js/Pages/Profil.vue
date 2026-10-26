<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const props = defineProps({
    user: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    contributions: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const statutBadge = (statut) => ({
    en_attente: 'text-bg-warning',
    validee: 'text-bg-success',
    refusee: 'text-bg-danger',
}[statut] ?? 'text-bg-secondary');

const typeLabel = (type) => ({
    statut: 'Statut',
    note: 'Note',
    lien: 'Lien',
    autre: 'Autre',
    date_debut: 'Dates',
}[type] ?? type);

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const anonymatForm = useForm({
    contributions_anonymes: Boolean(props.user.contributions_anonymes),
});

const updateAnonymat = () => {
    anonymatForm.put('/profil/anonymat');
};

const updatePassword = () => {
    passwordForm.put('/profil/password', {
        onSuccess: () => passwordForm.reset(),
    });
};

const deleteForm = useForm({ current_password: '' });
const confirmDelete = ref(false);

const deleteAccount = () => {
    if (! confirmDelete.value) {
        confirmDelete.value = true;

        return;
    }
    deleteForm.delete('/profil', {});
};

const formatDate = (iso) => new Date(iso).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});
</script>

<template>
    <Layout>
        <Seo noindex title="Mon espace" description="Espace membre de l'Observatoire des ABC : vos contributions et votre compte." />
        <div class="container py-4" style="max-width: 860px;">

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                <div>
                    <h1 class="h3 mb-1">Mon espace</h1>
                    <p class="text-muted small mb-0">
                        {{ user.name }} — {{ user.email }}
                        <span v-for="r in roles" :key="r" class="badge text-bg-secondary ms-1">{{ r }}</span>
                    </p>
                </div>
                <Link v-if="roles.includes('admin') || roles.includes('moderateur')" href="/admin"
                    class="btn btn-sm btn-outline-success">Panneau admin</Link>
            </div>

            <div v-if="flashSuccess" class="alert alert-success py-2">{{ flashSuccess }}</div>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Mes contributions ({{ contributions.length }})</h2>
                    <p v-if="contributions.length === 0" class="text-muted small mb-0">
                        Aucune contribution pour l'instant. Depuis la carte, ouvrez un projet ABC
                        et proposez une correction : elle apparaîtra ici avec son statut.
                    </p>
                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Projet</th>
                                    <th>Type</th>
                                    <th>Commentaire</th>
                                    <th>Statut</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in contributions" :key="c.id">
                                    <td>
                                        <Link v-if="c.projet_slug" :href="`/abc/${c.projet_slug}`">{{ c.projet }}</Link>
                                        <template v-else>{{ c.projet ?? c.projet_slug ?? '—' }}</template>
                                    </td>
                                    <td>{{ typeLabel(c.type) }}</td>
                                    <td class="text-truncate" style="max-width: 260px;">{{ c.commentaire ?? '—' }}</td>
                                    <td><span class="badge" :class="statutBadge(c.statut)">{{ c.statut }}</span></td>
                                    <td class="text-nowrap">{{ formatDate(c.date) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Anonymat de mes contributions</h2>
                    <form @submit.prevent="updateAnonymat">
                        <div class="form-check">
                            <input id="contributions_anonymes" v-model="anonymatForm.contributions_anonymes"
                                type="checkbox" class="form-check-input" />
                            <label class="form-check-label" for="contributions_anonymes">
                                Rendre mes contributions anonymes
                            </label>
                        </div>
                        <p class="form-text small">
                            Sur le site public (journal des mises à jour de l'accueil, historique des projets),
                            vos contributions apparaîtront comme « par un contributeur anonyme » au lieu de votre nom.
                            Les administrateurs et modérateurs conservent l'accès à l'identité des contributions,
                            y compris anonymes, pour la modération.
                        </p>
                        <button class="btn btn-outline-success btn-sm" :disabled="anonymatForm.processing">
                            Enregistrer
                        </button>
                    </form>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6 mb-3">Changer de mot de passe</h2>
                            <form @submit.prevent="updatePassword">
                                <label class="form-label" for="current_password">Mot de passe actuel</label>
                                <input id="current_password" v-model="passwordForm.current_password" type="password"
                                    class="form-control" autocomplete="current-password" required />
                                <div v-if="passwordForm.errors.current_password" class="text-danger small mt-1">
                                    {{ passwordForm.errors.current_password }}
                                </div>

                                <label class="form-label mt-3" for="new_password">Nouveau mot de passe</label>
                                <input id="new_password" v-model="passwordForm.password" type="password"
                                    class="form-control" autocomplete="new-password" required minlength="12" />
                                <div class="form-text">Au moins 12 caractères.</div>
                                <div v-if="passwordForm.errors.password" class="text-danger small mt-1">
                                    {{ passwordForm.errors.password }}
                                </div>

                                <label class="form-label mt-3" for="new_password_confirmation">Confirmer</label>
                                <input id="new_password_confirmation" v-model="passwordForm.password_confirmation"
                                    type="password" class="form-control" autocomplete="new-password" required minlength="12" />

                                <button class="btn btn-success w-100 mt-3" :disabled="passwordForm.processing">
                                    Mettre à jour
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border-danger h-100">
                        <div class="card-body">
                            <h2 class="h6 mb-3 text-danger">Supprimer mon compte</h2>
                            <p class="text-muted small">
                                La suppression est définitive. Vos contributions déjà enregistrées sont
                                conservées (elles deviennent anonymes), conformément au travail de
                                vérification déjà fourni.
                            </p>
                            <form @submit.prevent="deleteAccount">
                                <label class="form-label" for="delete_password">Confirmez avec votre mot de passe</label>
                                <input id="delete_password" v-model="deleteForm.current_password" type="password"
                                    class="form-control" autocomplete="current-password" required />
                                <div v-if="deleteForm.errors.current_password" class="text-danger small mt-1">
                                    {{ deleteForm.errors.current_password }}
                                </div>
                                <button class="btn w-100 mt-3" :class="confirmDelete ? 'btn-danger' : 'btn-outline-danger'"
                                    :disabled="deleteForm.processing">
                                    {{ confirmDelete ? 'Cliquer à nouveau pour supprimer définitivement' : 'Supprimer mon compte' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>
