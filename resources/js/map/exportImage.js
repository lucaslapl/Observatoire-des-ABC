/**
 * Export de la carte en PNG ou PDF. html2canvas et jspdf (≈700 Ko)
 * sont chargés à la demande uniquement, au moment de l'export.
 *
 * Les deux formats sont composés au ratio A4 paysage (297×210) : la carte
 * est temporairement agrandie à ce ratio exact (aucune zone visible n'est
 * perdue), capturée une fois les tuiles chargées, puis insérée pleine page
 * — d'où un PDF sans bandes blanches et un PNG directement imprimable.
 *
 * La classe `map-exporting` (composition légende / flèche du nord / échelle)
 * est posée et retirée impérativement via classList : passer par un binding
 * Vue obligerait Vue à réécrire tout l'attribut class du conteneur Leaflet
 * et effacerait ses classes (leaflet-container, etc.) — bug visuel permanent.
 *
 * @param {('png'|'pdf')} format
 * @param {Object} refs
 *   - mapEl, navEl, sidebarEl : éléments DOM concernés
 *   - legendRetracted : ref réactive « légende repliée »
 *   - exporting : ref réactive « export en cours »
 *   - leafletMap : instance Leaflet (redimensionnement + attente des tuiles)
 */
export const A4_LANDSCAPE_RATIO = 297 / 210;

/**
 * Dimensions d'export au ratio demandé : la dimension courte est
 * agrandie (jamais réduite), la zone visible ne peut que s'étendre.
 * Fonction pure, testée par tests/js/exportImage.test.mjs.
 */
export function fitRatio(width, height, ratio) {
    if (width / height > ratio) {
        return { width, height: Math.round(width / ratio) };
    }
    return { width: Math.round(height * ratio), height };
}

/** Fixe la taille du conteneur et retourne les styles inline à restaurer. */
function resizeMapEl(mapEl, leafletMap, { width, height }) {
    const prev = {
        flex: mapEl.style.flex,
        width: mapEl.style.width,
        height: mapEl.style.height,
    };
    mapEl.style.flex = 'none';
    mapEl.style.width = `${width}px`;
    mapEl.style.height = `${height}px`;
    leafletMap.invalidateSize();
    return prev;
}

/** Attend la fin du chargement des tuiles (événement `idle`), avec garde-fou. */
function waitForTiles(leafletMap) {
    if (!leafletMap) return Promise.resolve();
    return new Promise((resolve) => {
        let done = false;
        const finish = () => {
            if (done) return;
            done = true;
            leafletMap.off('idle', finish);
            resolve();
        };
        leafletMap.on('idle', finish);
        setTimeout(finish, 4000);
    });
}

export async function exportMap(format, { mapEl, navEl, sidebarEl, legendRetracted, exporting, leafletMap }) {
    const zoomCtrl = document.querySelector('.leaflet-control-zoom');
    const wasRetracted = legendRetracted.value;
    legendRetracted.value = false;
    if (zoomCtrl) zoomCtrl.style.display = 'none';
    navEl.style.visibility = 'hidden';
    if (sidebarEl) sidebarEl.style.visibility = 'hidden';
    mapEl.classList.add('map-exporting');

    let prevSize = null;
    if (leafletMap) {
        const rect = mapEl.getBoundingClientRect();
        prevSize = resizeMapEl(mapEl, leafletMap, fitRatio(
            Math.round(rect.width),
            Math.round(rect.height),
            A4_LANDSCAPE_RATIO,
        ));
    }

    exporting.value = true;
    try {
        await new Promise(r => requestAnimationFrame(r));
        await waitForTiles(leafletMap);

        // Imports à la demande : rien n'est chargé tant que l'utilisateur
        // n'exporte pas.
        const [{ default: html2canvas }] = await Promise.all([import('html2canvas')]);
        const canvas = await html2canvas(mapEl, {
            useCORS: true, backgroundColor: '#fff', scale: 2, logging: false,
        });

        if (format === 'png') {
            const link = document.createElement('a');
            link.download = 'carte-abc.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        } else if (format === 'pdf') {
            const { jsPDF } = await import('jspdf');
            const pdf = new jsPDF('landscape', 'mm', 'a4');
            const pw = pdf.internal.pageSize.getWidth();
            const ph = pdf.internal.pageSize.getHeight();
            // Le canvas est au ratio A4 exact : insertion pleine page,
            // sans bandes blanches ni déformation.
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, pw, ph);
            pdf.save('carte-abc.pdf');
        }
    } finally {
        exporting.value = false;
        if (zoomCtrl) zoomCtrl.style.display = '';
        navEl.style.visibility = '';
        if (sidebarEl) sidebarEl.style.visibility = '';
        mapEl.classList.remove('map-exporting');
        if (prevSize) {
            mapEl.style.flex = prevSize.flex;
            mapEl.style.width = prevSize.width;
            mapEl.style.height = prevSize.height;
            leafletMap.invalidateSize();
        }
        if (wasRetracted) legendRetracted.value = true;
    }
}
