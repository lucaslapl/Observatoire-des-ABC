<script setup>
import { reactive, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <div>
        <nav class="navbar navbar-dark navbar-abc py-2">
            <div class="container-fluid">
                <a class="navbar-brand fs-6 mb-0" href="/">🔐 Observatoire des ABC — Connexion</a>
                <a class="btn btn-sm btn-outline-light" href="/">← Carte</a>
            </div>
        </nav>

        <main class="d-flex align-items-center justify-content-center px-3" style="min-height: calc(100vh - 61px); background: #f1f5f9;">
            <div class="card shadow-sm w-100" style="max-width: 380px;">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Connexion</h2>
                    <form @submit.prevent="submit">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" v-model="form.email" type="email" class="form-control" autocomplete="username" required autofocus />
                        <div v-if="form.errors.email" class="text-danger small mt-1">{{ form.errors.email }}</div>

                        <label class="form-label mt-3" for="password">Mot de passe</label>
                        <input id="password" v-model="form.password" type="password" class="form-control" autocomplete="current-password" required />
                        <div v-if="form.errors.password" class="text-danger small mt-1">{{ form.errors.password }}</div>

                        <button class="btn btn-success w-100 mt-3" :disabled="form.processing">Se connecter</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
</style>