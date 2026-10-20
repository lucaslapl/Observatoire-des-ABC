<script setup>
import { useForm } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password', {
        onSuccess: () => form.reset('email'),
    });
};
</script>

<template>
    <div>
        <Seo noindex title="Mot de passe oublié" description="Réinitialisation du mot de passe de l'Observatoire des ABC." />
        <nav class="navbar navbar-dark navbar-abc py-2">
            <div class="container-fluid">
                <a class="navbar-brand fs-6 mb-0" href="/">🔐 Observatoire des ABC</a>
                <a class="btn btn-sm btn-outline-light" href="/login">← Connexion</a>
            </div>
        </nav>

        <main class="d-flex align-items-center justify-content-center px-3" style="min-height: calc(100vh - 61px); background: #f1f5f9;">
            <div class="card shadow-sm w-100" style="max-width: 420px;">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1">Mot de passe oublié</h2>
                    <p class="text-muted small mb-3">
                        Indiquez votre adresse email : nous vous enverrons un lien de réinitialisation.
                    </p>

                    <div v-if="status" class="alert alert-success small">{{ status }}</div>

                    <form @submit.prevent="submit">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" v-model="form.email" type="email" class="form-control" autocomplete="username" required autofocus />
                        <div v-if="form.errors.email" class="text-danger small mt-1">{{ form.errors.email }}</div>

                        <button class="btn btn-success w-100 mt-3" :disabled="form.processing">Envoyer le lien</button>
                    </form>

                    <div class="text-center small mt-3">
                        <a href="/login">Retour à la connexion</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
</style>
