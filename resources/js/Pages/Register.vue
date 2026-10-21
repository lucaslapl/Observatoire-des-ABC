<script setup>
import { useForm } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import BrandMark from '../Components/BrandMark.vue';
import InfoBanner from '../Components/InfoBanner.vue';

defineProps({
    raison: {
        type: String,
        default: null,
    },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <div>
        <Seo noindex title="Créer un compte" description="Créez un compte contributeur sur l'Observatoire des ABC." />
        <InfoBanner />
        <nav class="navbar navbar-dark navbar-abc py-2">
            <div class="container-fluid">
                <a class="navbar-brand fs-6 mb-0 d-inline-flex align-items-center gap-2" href="/"><BrandMark /> Observatoire des ABC — Inscription</a>
                <a class="btn btn-sm btn-outline-light" href="/">← Carte</a>
            </div>
        </nav>

        <main class="d-flex align-items-center justify-content-center px-3" style="min-height: calc(100vh - 61px); background: #f1f5f9;">
            <div class="card shadow-sm w-100" style="max-width: 420px;">
                <div class="card-body p-4">
                    <div v-if="raison === 'contribution'" class="alert alert-info small py-2 mb-3">
                        La suggestion de corrections depuis la carte nécessite un compte.
                        Créez-le ci-dessous, vérifiez votre adresse email, puis revenez sur la carte.
                    </div>
                    <h2 class="h5 mb-1">Créer un compte</h2>
                    <p class="text-muted small mb-3">
                        Un compte permet de suivre vos contributions et d'améliorer la qualité
                        des données. La vérification de votre adresse email est nécessaire.
                    </p>
                    <form @submit.prevent="submit">
                        <label class="form-label" for="name">Nom d'utilisateur</label>
                        <input id="name" v-model="form.name" type="text" class="form-control" autocomplete="nickname" required autofocus maxlength="100" />
                        <div v-if="form.errors.name" class="text-danger small mt-1">{{ form.errors.name }}</div>

                        <label class="form-label mt-3" for="email">Email</label>
                        <input id="email" v-model="form.email" type="email" class="form-control" autocomplete="username" required />
                        <div v-if="form.errors.email" class="text-danger small mt-1">{{ form.errors.email }}</div>

                        <label class="form-label mt-3" for="password">Mot de passe</label>
                        <input id="password" v-model="form.password" type="password" class="form-control" autocomplete="new-password" required minlength="12" />
                        <div class="form-text">Au moins 12 caractères.</div>
                        <div v-if="form.errors.password" class="text-danger small mt-1">{{ form.errors.password }}</div>

                        <label class="form-label mt-3" for="password_confirmation">Confirmer le mot de passe</label>
                        <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="form-control" autocomplete="new-password" required minlength="12" />

                        <button class="btn btn-success w-100 mt-3" :disabled="form.processing">Créer mon compte</button>
                    </form>

                    <div class="text-center small mt-3">
                        Déjà inscrit ?
                        <a href="/login">Se connecter</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
</style>
