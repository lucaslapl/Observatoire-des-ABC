/**
 * Export de la carte en PNG ou PDF. html2canvas et jspdf (≈700 Ko)
 * sont chargés à la demande uniquement, au moment de l'export.
 *
 * @param {('png'|'pdf')} format
 * @param {Object} refs
 *   - mapEl, legendEl, navEl, sidebarEl : éléments DOM concernés
 *   - legendRetracted : ref réactive « légende repliée »
 *   - exporting : ref réactive « export en cours »
 */
export async function exportMap(format, { mapEl, legendEl, navEl, sidebarEl, legendRetracted, exporting }) {
    const zoomCtrl = document.querySelector('.leaflet-control-zoom');
    const scaleCtrl = document.querySelector('.leaflet-control-scale');
    const wasRetracted = legendRetracted.value;
    legendRetracted.value = false;
    if (zoomCtrl) zoomCtrl.style.display = 'none';
    navEl.style.visibility = 'hidden';
    if (sidebarEl) sidebarEl.style.visibility = 'hidden';

    const legendHead = legendEl.querySelector('.legend-head');
    const origLegendBg = legendEl.style.background;
    const origLegendMaxW = legendEl.style.maxWidth;
    const origLegendW = legendEl.style.width;
    const origLegendShadow = legendEl.style.boxShadow;
    const origHeadBg = legendHead ? legendHead.style.background : null;
    const origScaleMargin = scaleCtrl ? scaleCtrl.style.marginLeft : null;
    if (scaleCtrl) scaleCtrl.style.marginLeft = '400px';
    legendEl.style.background = '#fff';
    legendEl.style.maxWidth = 'none';
    legendEl.style.width = '280px';
    legendEl.style.boxShadow = 'none';
    if (legendHead) legendHead.style.background = '#fff';

    exporting.value = true;
    try {
        await new Promise(r => requestAnimationFrame(r));

        // Imports à la demande : rien n'est chargé tant que l'utilisateur
        // n'exporte pas.
        const [{ default: html2canvas }] = await Promise.all([import('html2canvas')]);
        const canvas = await html2canvas(mapEl, {
            useCORS: true, backgroundColor: '#fff', scale: 1, logging: false,
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
            const r = Math.min(pw / canvas.width, ph / canvas.height);
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', (pw - canvas.width * r) / 2, (ph - canvas.height * r) / 2, canvas.width * r, canvas.height * r);
            pdf.save('carte-abc.pdf');
        }
    } finally {
        exporting.value = false;
        if (zoomCtrl) zoomCtrl.style.display = '';
        if (scaleCtrl) scaleCtrl.style.marginLeft = origScaleMargin;
        navEl.style.visibility = '';
        if (sidebarEl) sidebarEl.style.visibility = '';
        legendEl.style.background = origLegendBg;
        legendEl.style.maxWidth = origLegendMaxW;
        legendEl.style.width = origLegendW;
        legendEl.style.boxShadow = origLegendShadow;
        if (legendHead) legendHead.style.background = origHeadBg;
        if (wasRetracted) legendRetracted.value = true;
    }
}
