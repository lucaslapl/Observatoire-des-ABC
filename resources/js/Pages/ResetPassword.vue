<script setup>
import { useForm } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import InfoBanner from '../Components/InfoBanner.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <div>
        <Seo noindex title="Nouveau mot de passe" description="Définissez un nouveau mot de passe pour votre compte." />
        <InfoBanner />
        <nav class="navbar navbar-dark navbar-abc py-2">
            <div class="container-fluid">
                <a class="navbar-brand fs-6 mb-0" href="/">🔐 Observatoire des ABC</a>
                <a class="btn btn-sm btn-outline-light" href="/">← Carte</a>
            </div>
        </nav>

        <main class="d-flex align-items-center justify-content-center px-3" style="min-height: calc(100vh - 61px); background: #f1f5f9;">
            <div class="card shadow-sm w-100" style="max-width: 420px;">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Nouveau mot de passe</h2>
                    <form @submit.prevent="submit">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" v-model="form.email" type="email" class="form-control" autocomplete="username" required />
                        <div v-if="form.errors.email" class="text-danger small mt-1">{{ form.errors.email }}</div>

                        <label class="form-label mt-3" for="password">Mot de passe</label>
                        <input id="password" v-model="form.password" type="password" class="form-control" autocomplete="new-password" required minlength="12" />
                        <div class="form-text">Au moins 12 caractères.</div>
                        <div v-if="form.errors.password" class="text-danger small mt-1">{{ form.errors.password }}</div>

                        <label class="form-label mt-3" for="password_confirmation">Confirmer le mot de passe</label>
                        <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="form-control" autocomplete="new-password" required minlength="12" />

                        <button class="btn btn-success w-100 mt-3" :disabled="form.processing">Réinitialiser le mot de passe</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
</style>
