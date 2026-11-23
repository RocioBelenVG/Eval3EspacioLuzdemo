<?php
/**
 * Región -> comuna para los formularios de paciente. Los datos salen de las
 * tablas `regiones` y `comunas` (catalogoRegiones()). Expone window.ezGeo:
 *   ezGeo.bind(form)                  conecta el select de región con el de comuna
 *   ezGeo.set(form, region, comuna)   fija ambos valores (al editar)
 *   ezGeo.setSelect(select, valor)    fija un select sin perder valores fuera de lista
 */
declare(strict_types=1);

$geoData = [];
foreach (catalogoRegiones() as $geoR) {
    $geoData[$geoR['nombre']] = $geoR['comunas'];
}
?>
<script>
window.ezGeo = (function () {
    var DATA = <?= json_encode($geoData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    function opt(v, t) { var o = document.createElement('option'); o.value = v; o.textContent = t; return o; }
    function fill(form, comunaSel) {
        var reg = form.querySelector('[data-geo-region]'), com = form.querySelector('[data-geo-comuna]');
        if (!reg || !com) return;
        var lista = DATA[reg.value] || [];
        com.innerHTML = '';
        com.appendChild(opt('', reg.value ? '— Seleccionar comuna —' : '— Primero elige la región —'));
        lista.forEach(function (c) { com.appendChild(opt(c, c)); });
        com.disabled = !reg.value;
        com.value = comunaSel && lista.indexOf(comunaSel) >= 0 ? comunaSel : '';
    }
    function bind(form) {
        var reg = form.querySelector('[data-geo-region]');
        if (reg) reg.addEventListener('change', function () { fill(form, ''); });
        fill(form, '');
    }
    function set(form, region, comuna) {
        var reg = form.querySelector('[data-geo-region]');
        if (!reg) return;
        if (!region && comuna) {
            for (var r in DATA) { if (DATA[r].indexOf(comuna) >= 0) { region = r; break; } }
        }
        reg.value = region || '';
        fill(form, comuna || '');
    }
    function setSelect(sel, val) {
        if (!sel) return;
        sel.value = val || '';
        if (val && sel.value !== val) { sel.appendChild(opt(val, val + ' (valor actual)')); sel.value = val; }
    }
    return { bind: bind, set: set, setSelect: setSelect };
})();
</script>
