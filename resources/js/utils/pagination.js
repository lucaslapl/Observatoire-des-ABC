/* Pagination côté client des listes paginées côté serveur (Laravel/Inertia).
   Un paginateur Inertia (LengthAwarePaginator::toArray()) expose :
   current_page, last_page, from, to, total, prev_page_url, next_page_url et
   links (tableau d'entrées {url, label, active}). On prend n'importe quelle
   URL de `links` comme gabarit et on remplace uniquement le numéro de page. */

/** URL relative vers la page demandée d'un paginateur. */
export function pageLink(paginator, page, pageParam = 'page') {
    const links = paginator?.links;
    let template = null;
    if (Array.isArray(links)) {
        template = links.find((l) => l && typeof l.url === 'string' && l.url)?.url ?? null;
    } else if (links && typeof links === 'object') {
        template = links.first || links.last || null;
    }
    if (!template) return '#';
    const url = new URL(template);
    url.searchParams.set(pageParam, String(page));
    return `${url.pathname}?${url.searchParams.toString()}`;
}

/** Fenêtre de numéros à afficher : 1 … n-1 n n+1 … dernière. */
export function pageWindow(current, last, padding = 1) {
    if (!Number.isFinite(current) || !Number.isFinite(last) || last < 1) return [];
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }
    const pages = [1];
    const from = Math.max(2, current - padding);
    const to = Math.min(last - 1, current + padding);
    if (from > 2) pages.push('…');
    for (let n = from; n <= to; n += 1) pages.push(n);
    if (to < last - 1) pages.push('…');
    pages.push(last);
    return pages;
}
