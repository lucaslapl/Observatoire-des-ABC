<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const page = usePage();
const flash = computed(() => page.props.flash || {});
const user = computed(() => page.props.auth?.user);

const form = useForm({
    name: user.value?.name ?? '',
    email: user.value?.email ?? '',
    message: '',
});

const submit = () => {
    form.post('/contact', {
        onSuccess: () => form.reset('message'),
    });
};
</script>

<template>
    <Layout>
        <Seo
            title="Contact"
            description="Signalez un bug ou écrivez à l'équipe de l'Observatoire des ABC via le formulaire de contact."
        />
        <div class="container py-4" style="max-width: 720px;">
            <h1 class="h3 mb-2">Contact</h1>
            <p class="text-muted">
                Vous repérez un bug, une donnée erronée, ou souhaitez simplement nous écrire ?
                Utilisez le formulaire ci-dessous : votre message arrive directement à l'équipe.
            </p>

            <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>

            <form class="card card-body shadow-sm mt-3" @submit.prevent="submit">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">Nom <span class="text-muted">(facultatif)</span></label>
                        <input id="name" v-model="form.name" type="text" class="form-control" maxlength="100" autocomplete="name" />
                        <div v-if="form.errors.name" class="text-danger small mt-1">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" v-model="form.email" type="email" class="form-control" required autocomplete="email" />
                        <div v-if="form.errors.email" class="text-danger small mt-1">{{ form.errors.email }}</div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="message">Message</label>
                    <textarea id="message" v-model="form.message" class="form-control" rows="7" required
                        minlength="20" maxlength="5000" placeholder="Décrivez le bug ou votre message (page concernée, ce que vous attendiez, ce qui s'est affiché…)"></textarea>
                    <div v-if="form.errors.message" class="text-danger small mt-1">{{ form.errors.message }}</div>
                </div>

                <button class="btn btn-success mt-3" :disabled="form.processing">Envoyer le message</button>
            </form>
        </div>
    </Layout>
</template>
