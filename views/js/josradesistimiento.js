/**
 * josradesistimiento.js
 * Mejoras UX: validación cliente, feedback visual, autocompletado
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // ---- Formulario principal ----
    var form = document.getElementById('josra-form-desistimiento');
    if (form) {
      initFormulario(form);
    }

    // ---- Formulario retención ----
    var formRetencion = document.getElementById('josra-form-retencion');
    if (formRetencion) {
      initRetencion(formRetencion);
    }

  });

  /* ============================================================
     FORMULARIO DE DESISTIMIENTO
     ============================================================ */

  function initFormulario(form) {
    var btnEnviar = document.getElementById('josra-btn-enviar');

    form.addEventListener('submit', function (e) {
      var valido = validarFormulario(form);
      if (!valido) {
        e.preventDefault();
        return;
      }

      // Feedback visual — evitar doble envío
      if (btnEnviar) {
        btnEnviar.disabled = true;
        var loadingText = btnEnviar.getAttribute('data-loading-text') || 'Enviando…';
        btnEnviar.textContent = loadingText;
      }
    });

    // Contador de caracteres para comentario
    var textarea = form.querySelector('#josra_comentario');
    if (textarea) {
      var counter = document.createElement('small');
      counter.className = 'josra-char-counter form-text text-muted';
      textarea.parentNode.appendChild(counter);

      function actualizarContador() {
        var restantes = 1000 - textarea.value.length;
        counter.textContent = restantes + ' caracteres restantes';
      }

      textarea.addEventListener('input', actualizarContador);
      actualizarContador();
    }
  }

  function validarFormulario(form) {
    var errores = [];
    var nombre = form.querySelector('#josra_nombre');
    var email  = form.querySelector('#josra_email');
    var ref    = form.querySelector('#josra_referencia');
    var motivo = form.querySelector('#josra_motivo');

    limpiarErrores(form);

    if (nombre && nombre.value.trim().length < 2) {
      marcarError(nombre, 'El nombre es obligatorio.');
      errores.push('nombre');
    }

    if (email && !validarEmail(email.value.trim())) {
      marcarError(email, 'Introduce un email válido.');
      errores.push('email');
    }

    if (ref && ref.value.trim() === '') {
      marcarError(ref, 'La referencia del pedido es obligatoria.');
      errores.push('referencia');
    }

    if (motivo && motivo.value === '') {
      marcarError(motivo, 'Selecciona un motivo.');
      errores.push('motivo');
    }

    if (errores.length > 0) {
      var primerError = form.querySelector('.josra-field-error');
      if (primerError) {
        primerError.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }

    return errores.length === 0;
  }

  function marcarError(campo, mensaje) {
    campo.classList.add('josra-field-error');
    campo.style.borderColor = '#e74c3c';
    var msg = document.createElement('div');
    msg.className = 'josra-error-msg text-danger';
    msg.style.fontSize = '12px';
    msg.style.marginTop = '4px';
    msg.textContent = mensaje;
    campo.parentNode.appendChild(msg);
  }

  function limpiarErrores(form) {
    form.querySelectorAll('.josra-field-error').forEach(function (el) {
      el.classList.remove('josra-field-error');
      el.style.borderColor = '';
    });
    form.querySelectorAll('.josra-error-msg').forEach(function (el) {
      el.parentNode.removeChild(el);
    });
  }

  function validarEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /* ============================================================
     FORMULARIO RETENCIÓN
     ============================================================ */

  function initRetencion(form) {
    var radios = form.querySelectorAll('input[type="radio"]');
    var btnConfirmar = form.querySelector('button[type="submit"]');

    radios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        // Actualizar texto del botón según opción
        if (!btnConfirmar) return;

        if (radio.value === 'continuar') {
          btnConfirmar.textContent = 'Continuar con el desistimiento';
        } else if (radio.value === 'saldo') {
          btnConfirmar.textContent = 'Quiero el saldo en tienda';
        } else if (radio.value === 'cambio') {
          btnConfirmar.textContent = 'Gestionar el cambio';
        }
      });
    });

    form.addEventListener('submit', function () {
      if (btnConfirmar) {
        btnConfirmar.disabled = true;
        btnConfirmar.textContent = 'Procesando…';
      }
    });
  }

})();
