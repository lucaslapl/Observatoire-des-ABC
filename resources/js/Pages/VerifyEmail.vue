<script setup>
import { useForm } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import BrandMark from '../Components/BrandMark.vue';
import InfoBanner from '../Components/InfoBanner.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({});

const resend = () => {
    form.post('/verification-email/send', {
        preserveScroll: true,
    });
};

const logout = () => form.post('/logout');
</script>

<template>
    <div>
        <Seo noindex title="Vérification de l'email" description="Vérifiez votre adresse email pour activer votre compte." />
        <InfoBanner />
        <nav class="navbar navbar-dark navbar-abc py-2">
            <div class="container-fluid">
                <a class="navbar-brand fs-6 mb-0 d-inline-flex align-items-center gap-2" href="/"><BrandMark /> Observatoire des ABC</a>
                <button class="btn btn-sm btn-outline-light" type="button" @click="logout">Déconnexion</button>
            </div>
        </nav>

        <main class="d-flex align-items-center justify-content-center px-3" style="min-height: calc(100vh - 61px); background: #f1f5f9;">
            <div class="card shadow-sm w-100" style="max-width: 460px;">
                <div class="card-body p-4 text-center">
                    <h2 class="h5 mb-3">Vérifiez votre boîte mail</h2>
                    <p class="text-muted small">
                        Un lien de vérification vous a été envoyé par email.
                        Cliquez dessus pour activer votre compte et accéder à votre espace.
                    </p>

                    <div v-if="status === 'verification-link-sent'" class="alert alert-success small">
                        Un nouveau lien vient d'être envoyé.
                    </div>

                    <button class="btn btn-outline-success btn-sm mt-2" :disabled="form.processing" @click="resend">
                        Renvoyer le lien de vérification
                    </button>
                </div>
            </div>
        </main>
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
</style>
