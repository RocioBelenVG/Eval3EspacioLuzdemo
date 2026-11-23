<?php
/**
 * Validación en vivo de los formularios de paciente (nuevo y editar).
 * Expone window.ezPac:
 *   ezPac.bind(form)     RUT del paciente/apoderado con dígito verificador y formato
 *                        12.345.678-9; edad según la fecha de nacimiento y, si es
 *                        menor de 18, tutor/apoderado obligatorio.
 *   ezPac.refresh(form)  vuelve a evaluar (tras cargar valores al editar).
 *   ezPac.setDocumento(form, tipo, numero)  fija tipo (rut/pasaporte/extranjero) y número.
 * Para extranjeros sin RUT el número de pasaporte/documento va en el mismo campo `rut`
 * y el servidor lo guarda en documento_numero.
 * El servidor aplica las mismas reglas (validarIdentidadPaciente en lib/pacientes.php).
 */
declare(strict_types=1);
?>
<script>
window.ezPac = (function () {
    var MAYORIA = <?= (int) PACIENTE_MAYORIA_EDAD ?>;
    var ROJO = '#dc2626', TERRACOTA = '#D97757';

    // ---- RUT: módulo 11 + formato 12.345.678-9 ----
    function rutLimpio(r) { return String(r || '').toUpperCase().replace(/[^0-9K]/g, ''); }
    function rutDv(c) { var s = 0, m = 2; for (var i = c.length - 1; i >= 0; i--) { s += parseInt(c[i], 10) * m; m = m === 7 ? 2 : m + 1; } var r = 11 - (s % 11); return r === 11 ? '0' : r === 10 ? 'K' : String(r); }
    function rutValido(r) { var l = rutLimpio(r); if (!/^\d{7,8}[0-9K]$/.test(l)) return false; return rutDv(l.slice(0, -1)) === l.slice(-1); }
    function rutFormato(r) { var l = rutLimpio(r), c = l.slice(0, -1).replace(/^0+/, ''); return c.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + l.slice(-1); }

    function tieneTutor(v) { v = String(v || '').trim(); return v !== '' && !/^no aplica/i.test(v); }
    function hoyIso() { var d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
    /** Años cumplidos; null sin fecha válida; -1 si la fecha es futura. */
    function edad(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!m) return null;
        var y = +m[1], mo = +m[2], d = +m[3], h = hoyIso().split('-').map(Number);
        if (iso > hoyIso()) return -1;
        var e = h[0] - y;
        if (h[1] < mo || (h[1] === mo && h[2] < d)) e--;
        return e;
    }

    var DOC = { rut: { label: 'RUT', ph: '12.345.678-9' }, pasaporte: { label: 'N° de pasaporte', ph: 'Ej: AB1234567' }, extranjero: { label: 'N° de documento extranjero', ph: 'Ej: DNI 30123456' } };
    function docTipo(form) { return form.documento_tipo && DOC[form.documento_tipo.value] ? form.documento_tipo.value : 'rut'; }
    function caja(input) { return input.closest('[data-campo]') || input.parentNode; }
    function setMsg(input, texto, tono) {
        var c = caja(input), s = c.querySelector('[data-pac-msg]');
        if (!s) { s = document.createElement('span'); s.setAttribute('data-pac-msg', ''); c.appendChild(s); }
        s.textContent = texto || '';
        s.className = 'font-body text-[11px] font-medium leading-snug ' + (tono === 'mal' ? 'text-red-600' : tono === 'alerta' ? 'text-terracota' : 'text-salvia-dark');
        s.hidden = !texto;
    }

    function revisarDocExtranjero(input) {
        var v = input.value.replace(/\s+/g, '').toUpperCase();
        if (!v) { input.setCustomValidity(''); input.style.borderColor = ''; setMsg(input, ''); return true; }
        input.value = v;
        var ok = /^[A-Z0-9][A-Z0-9.\-]{3,29}$/.test(v);
        input.setCustomValidity(ok ? '' : 'Número no válido: usa solo letras y números (entre 4 y 30 caracteres).');
        input.style.borderColor = ok ? '' : ROJO;
        setMsg(input, ok ? '' : 'Número no válido: solo letras y números (4 a 30 caracteres).', 'mal');
        return ok;
    }

    function revisarRut(input, silencioso) {
        if (input.name === 'rut' && input.form && docTipo(input.form) !== 'rut') return revisarDocExtranjero(input);
        var v = input.value.trim();
        if (!v) { input.setCustomValidity(''); input.style.borderColor = ''; setMsg(input, ''); return true; }
        var ok = rutValido(v);
        if (ok) input.value = rutFormato(v);
        input.setCustomValidity(ok ? '' : 'RUT no válido: revisa el número y el dígito verificador (ej: 12.345.678-9).');
        input.style.borderColor = ok ? '' : ROJO;
        setMsg(input, ok ? (silencioso ? '' : '✓ RUT válido') : 'RUT no válido: revisa el número y el dígito verificador.', ok ? 'ok' : 'mal');
        return ok;
    }

    function revisarEdad(form) {
        var fn = form.fecha_nacimiento, ap = form.apoderado;
        if (!fn || !ap) return;
        var e = edad(fn.value), menor = e !== null && e >= 0 && e < MAYORIA;
        if (e === -1) {
            fn.setCustomValidity('La fecha de nacimiento no puede ser futura.');
            setMsg(fn, 'La fecha no puede ser futura.', 'mal');
        } else {
            fn.setCustomValidity('');
            setMsg(fn, e === null ? '' : (menor
                ? 'Menor de edad · ' + e + (e === 1 ? ' año' : ' años') + ' — tutor obligatorio'
                : 'Mayor de edad · ' + e + ' años'), menor ? 'alerta' : 'ok');
        }
        // Tutor / apoderado: obligatorio solo para menores.
        var lbl = caja(ap).querySelector('span:not([data-pac-msg])');
        if (lbl) { if (!lbl.dataset.base) lbl.dataset.base = lbl.textContent; lbl.textContent = lbl.dataset.base + (menor ? ' *' : ''); }
        if (!ap.dataset.ph) ap.dataset.ph = ap.placeholder;
        ap.placeholder = menor ? 'Nombre y relación, ej: Ana Pérez (madre)' : ap.dataset.ph;
        ap.required = menor;
        var falta = menor && !tieneTutor(ap.value);
        ap.setCustomValidity(falta ? 'El paciente es menor de edad: indica el nombre del tutor o apoderado.' : '');
        ap.style.borderColor = menor ? (falta ? TERRACOTA : '') : '';
        setMsg(ap, menor && /^no aplica/i.test(ap.value.trim()) ? 'Es menor de edad: «No aplica» no sirve, indica el tutor.' : '', 'mal');
    }

    function aplicarTipo(form) {
        if (!form.documento_tipo || !form.rut) return;
        var d = DOC[docTipo(form)], lbl = caja(form.rut).querySelector('[data-doc-label]');
        if (lbl) lbl.textContent = d.label;
        form.rut.placeholder = d.ph;
    }
    function setDocumento(form, tipo, numero) {
        if (form.documento_tipo) form.documento_tipo.value = DOC[tipo] ? tipo : 'rut';
        if (form.rut) form.rut.value = numero || '';
        aplicarTipo(form);
    }

    function refresh(form) {
        aplicarTipo(form);
        ['rut', 'apoderado_rut'].forEach(function (k) { if (form[k]) revisarRut(form[k], true); });
        if (form.fecha_nacimiento) form.fecha_nacimiento.max = hoyIso();
        revisarEdad(form);
    }

    function bind(form) {
        ['rut', 'apoderado_rut'].forEach(function (k) {
            var i = form[k];
            if (!i) return;
            i.addEventListener('blur', function () { revisarRut(i, false); });
            i.addEventListener('input', function () { i.setCustomValidity(''); i.style.borderColor = ''; setMsg(i, ''); });
        });
        if (form.documento_tipo) form.documento_tipo.addEventListener('change', function () { aplicarTipo(form); if (form.rut) revisarRut(form.rut, true); });
        if (form.fecha_nacimiento) {
            form.fecha_nacimiento.addEventListener('input', function () { revisarEdad(form); });
            form.fecha_nacimiento.addEventListener('change', function () { revisarEdad(form); });
        }
        if (form.apoderado) form.apoderado.addEventListener('input', function () { revisarEdad(form); });
        // Al enviar, los RUT se revisan aunque no se haya salido del campo.
        var btn = form.querySelector('button[type=submit]');
        if (btn) btn.addEventListener('click', function () { ['rut', 'apoderado_rut'].forEach(function (k) { if (form[k]) revisarRut(form[k], true); }); revisarEdad(form); });
        refresh(form);
    }

    return { bind: bind, refresh: refresh, setDocumento: setDocumento, rutValido: rutValido, rutFormato: rutFormato, edad: edad };
})();
</script>
