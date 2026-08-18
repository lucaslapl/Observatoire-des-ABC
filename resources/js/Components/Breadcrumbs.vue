<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <nav aria-label="Fil d'Ariane">
        <ol class="breadcrumb small mb-3">
            <li class="breadcrumb-item">
                <Link href="/">Accueil</Link>
            </li>
            <li
                v-for="(item, i) in items"
                :key="i"
                class="breadcrumb-item"
                :class="{ active: !item.url }"
                :aria-current="item.url ? undefined : 'page'"
            >
                <Link v-if="item.url" :href="item.url">{{ item.label }}</Link>
                <span v-else>{{ item.label }}</span>
            </li>
        </ol>
    </nav>
</template>

<style scoped>
.breadcrumb { background: transparent; padding: 0; }
</style>
