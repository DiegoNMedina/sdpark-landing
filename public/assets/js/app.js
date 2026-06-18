(function () {
  const form = document.querySelector('[data-reservation-form]');

  if (!form) {
    return;
  }

  const startDate = form.querySelector('[data-start-date]');
  const startTime = form.querySelector('[data-start-time]');
  const endDate = form.querySelector('[data-end-date]');
  const endTime = form.querySelector('[data-end-time]');
  const lotSelect = form.querySelector('[data-rate-source]');
  const estimateTotal = form.querySelector('[data-estimate-total]');
  const steps = Array.from(form.querySelectorAll('[data-form-step]'));
  const indicators = Array.from(form.querySelectorAll('[data-step-indicator]'));
  const summaryDropoff = form.querySelector('[data-summary-dropoff]');
  const summaryPickup = form.querySelector('[data-summary-pickup]');
  const summaryCustomer = form.querySelector('[data-summary-customer]');
  let currentStep = 0;
  const currency = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  });

  const today = new Date();
  const tomorrow = new Date(today.getTime() + 24 * 60 * 60 * 1000);

  function dateValue(date) {
    return date.toISOString().slice(0, 10);
  }

  function dateTime(dateInput, timeInput) {
    if (!dateInput.value || !timeInput.value) {
      return null;
    }

    return new Date(`${dateInput.value}T${timeInput.value}:00`);
  }

  function selectedRateCents() {
    const selected = lotSelect.options[lotSelect.selectedIndex];
    return Number(selected.dataset.rate || 1895);
  }

  function selectedLabel(select) {
    return select.options[select.selectedIndex] ? select.options[select.selectedIndex].textContent.trim() : '';
  }

  function formattedDate(value) {
    if (!value) {
      return '';
    }

    const parts = value.split('-');

    if (parts.length !== 3) {
      return value;
    }

    return `${parts[1]}/${parts[2]}/${parts[0]}`;
  }

  function clearStepError(stepIndex) {
    const error = form.querySelector(`[data-step-error="${stepIndex}"]`);

    if (!error) {
      return;
    }

    error.textContent = '';
    error.classList.remove('is-visible');
  }

  function showStepError(stepIndex, message) {
    const error = form.querySelector(`[data-step-error="${stepIndex}"]`);

    if (!error) {
      return;
    }

    error.textContent = message;
    error.classList.add('is-visible');
  }

  function validateStep(stepIndex) {
    clearStepError(stepIndex);

    if (stepIndex === 0) {
      if (!startDate.value || !startTime.value || !endDate.value || !endTime.value) {
        showStepError(stepIndex, 'Please choose your drop-off and pick-up dates and times.');
        return false;
      }

      const start = dateTime(startDate, startTime);
      const end = dateTime(endDate, endTime);

      if (!start || !end || end <= start) {
        showStepError(stepIndex, 'Pick-up must be after drop-off.');
        return false;
      }
    }

    if (stepIndex === 1) {
      const requiredFields = ['first_name', 'last_name', 'email', 'phone'];
      const missing = requiredFields.some(function (fieldName) {
        return !form.elements[fieldName].value.trim();
      });

      if (missing) {
        showStepError(stepIndex, 'Please enter your name, email, and phone number.');
        return false;
      }

      if (!form.elements.email.checkValidity()) {
        showStepError(stepIndex, 'Please enter a valid email address.');
        return false;
      }
    }

    if (stepIndex === 2 && !lotSelect.value) {
      showStepError(stepIndex, 'Please choose a parking lot.');
      return false;
    }

    return true;
  }

  function updateSummary() {
    const firstName = form.elements.first_name.value.trim();
    const lastName = form.elements.last_name.value.trim();
    const customer = `${firstName} ${lastName}`.trim();

    summaryDropoff.textContent = `${formattedDate(startDate.value)} at ${selectedLabel(startTime)}`;
    summaryPickup.textContent = `${formattedDate(endDate.value)} at ${selectedLabel(endTime)}`;
    summaryCustomer.textContent = customer || '--';
  }

  function updateEstimate() {
    const start = dateTime(startDate, startTime);
    const end = dateTime(endDate, endTime);
    let days = 1;

    if (start && end && end > start) {
      const hours = (end.getTime() - start.getTime()) / 36e5;
      days = Math.max(1, Math.ceil(hours / 24));
    }

    estimateTotal.textContent = currency.format((selectedRateCents() * days) / 100);
  }

  function showStep(stepIndex) {
    currentStep = Math.max(0, Math.min(stepIndex, steps.length - 1));

    steps.forEach(function (step, index) {
      step.classList.toggle('is-active', index === currentStep);
    });

    indicators.forEach(function (indicator, index) {
      indicator.classList.toggle('is-active', index === currentStep);
      indicator.classList.toggle('is-complete', index < currentStep);
    });

    updateEstimate();
    updateSummary();
  }

  startDate.min = dateValue(today);
  endDate.min = dateValue(today);

  if (!startDate.value) {
    startDate.value = dateValue(today);
  }

  if (!endDate.value) {
    endDate.value = dateValue(tomorrow);
  }

  startTime.value = '08:00';
  endTime.value = '08:00';

  form.addEventListener('change', function () {
    if (startDate.value) {
      endDate.min = startDate.value;
    }

    updateEstimate();
    updateSummary();
    clearStepError(currentStep);
  });

  form.addEventListener('input', function () {
    updateSummary();
    clearStepError(currentStep);
  });

  form.querySelectorAll('[data-next-step]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!validateStep(currentStep)) {
        return;
      }

      showStep(currentStep + 1);
    });
  });

  form.querySelectorAll('[data-prev-step]').forEach(function (button) {
    button.addEventListener('click', function () {
      showStep(currentStep - 1);
    });
  });

  form.addEventListener('submit', function (event) {
    for (let index = 0; index < steps.length; index += 1) {
      if (!validateStep(index)) {
        event.preventDefault();
        showStep(index);
        return;
      }
    }
  });

  document.querySelectorAll('[data-lot-jump]').forEach(function (link) {
    link.addEventListener('click', function () {
      lotSelect.value = link.dataset.lotJump;
      updateEstimate();
      updateSummary();
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  showStep(0);
}());
