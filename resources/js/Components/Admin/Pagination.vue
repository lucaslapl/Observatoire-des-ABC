<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { pageLink, pageWindow } from '../../utils/pagination.js';

// Paginateur Inertia (toArray()) : current_page, last_page, total, from, to,
// prev_page_url, next_page_url, links. `pageParam` distingue les paginateurs
// multiples d'une même page (?vpage= / ?fpage= des journaux d'audit).
const props = defineProps({
    paginator: { type: Object, required: true },
    pageParam: { type: String, default: 'page' },
});

const items = computed(() => pageWindow(props.paginator.current_page, props.paginator.last_page));
const href = (n) => pageLink(props.paginator, n, props.pageParam);
</script>

<template>
    <nav v-if="paginator.last_page > 1" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Pagination">
        <span class="small text-muted">
            {{ paginator.from }}–{{ paginator.to }} sur {{ paginator.total.toLocaleString('fr-FR') }}
        </span>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item" :class="{ disabled: !paginator.prev_page_url }">
                <Link v-if="paginator.prev_page_url" class="page-link" :href="paginator.prev_page_url" preserve-scroll aria-label="Page précédente">«</Link>
                <span v-else class="page-link" aria-hidden="true">«</span>
            </li>
            <template v-for="(item, i) in items" :key="`${item}-${i}`">
                <li v-if="item === '…'" class="page-item disabled"><span class="page-link">…</span></li>
                <li v-else class="page-item" :class="{ active: item === paginator.current_page }">
                    <Link v-if="item !== paginator.current_page" class="page-link" :href="href(item)" preserve-scroll :aria-label="`Page ${item}`">{{ item }}</Link>
                    <span v-else class="page-link" aria-current="page">{{ item }}</span>
                </li>
            </template>
            <li class="page-item" :class="{ disabled: !paginator.next_page_url }">
                <Link v-if="paginator.next_page_url" class="page-link" :href="paginator.next_page_url" preserve-scroll aria-label="Page suivante">»</Link>
                <span v-else class="page-link" aria-hidden="true">»</span>
            </li>
        </ul>
    </nav>
</template>
