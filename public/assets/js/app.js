(function () {
  const forms = Array.from(document.querySelectorAll('[data-reservation-form]'));

  if (!forms.length) {
    return;
  }

  forms.forEach(initReservationForm);

  function initReservationForm(form) {
  const startDate = form.querySelector('[data-start-date]');
  const startTime = form.querySelector('[data-start-time]');
  const endDate = form.querySelector('[data-end-date]');
  const endTime = form.querySelector('[data-end-time]');
  const lotSelect = form.querySelector('[data-rate-source]');
  const lotPills = Array.from(form.querySelectorAll('[data-lot-pill]'));
  const estimateTotal = form.querySelector('[data-estimate-total]');
  const steps = Array.from(form.querySelectorAll('[data-form-step]'));
  const indicators = Array.from(form.querySelectorAll('[data-step-indicator]'));
  const summaryDropoff = form.querySelector('[data-summary-dropoff]');
  const summaryPickup = form.querySelector('[data-summary-pickup]');
  const summaryCustomer = form.querySelector('[data-summary-customer]');
  const recaptchaToken = form.querySelector('[data-recaptcha-token]');
  let currentStep = 0;
  let recaptchaReadyToSubmit = false;
  const currentDateValue = form.dataset.currentDate;
  const currentTimeValue = form.dataset.currentTime;
  const minReservationDays = Math.max(1, Number(form.dataset.minReservationDays || 1));
  const recaptchaSiteKey = form.dataset.recaptchaSiteKey || '';
  const currency = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  });

  const today = parseDateValue(currentDateValue) || new Date();
  const tomorrow = addDays(today, 1);

  function dateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }

  function parseDateValue(value) {
    if (!value) {
      return null;
    }

    const parts = value.split('-').map(Number);

    if (parts.length !== 3 || parts.some(Number.isNaN)) {
      return null;
    }

    return new Date(parts[0], parts[1] - 1, parts[2]);
  }

  function addDays(date, days) {
    const next = new Date(date.getTime());
    next.setDate(next.getDate() + days);
    return next;
  }

  function timeMinutes(value) {
    const parts = value.split(':').map(Number);

    if (parts.length !== 2 || parts.some(Number.isNaN)) {
      return null;
    }

    return parts[0] * 60 + parts[1];
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
      const now = new Date(`${currentDateValue}T${currentTimeValue}:00`);

      if (!start || !end || end <= start) {
        showStepError(stepIndex, 'Pick-up must be after drop-off.');
        return false;
      }

      if (start < now) {
        showStepError(stepIndex, 'Drop-off date and time cannot be in the past.');
        return false;
      }

      if (end < now) {
        showStepError(stepIndex, 'Pick-up date and time cannot be in the past.');
        return false;
      }

      if (reservationDurationDays(start, end) < minReservationDays) {
        showStepError(stepIndex, `Reservation must be at least ${minReservationDays} day${minReservationDays === 1 ? '' : 's'}.`);
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
      days = reservationDays(start, end);
    }

    estimateTotal.textContent = currency.format((selectedRateCents() * days) / 100);
  }

  function syncLotPills() {
    lotPills.forEach(function (pill) {
      const isActive = pill.dataset.lotPill === lotSelect.value;
      pill.classList.toggle('is-active', isActive);
      pill.setAttribute('aria-checked', isActive ? 'true' : 'false');
    });
  }

  function reservationDays(start, end) {
    const hours = (end.getTime() - start.getTime()) / 36e5;
    return Math.max(1, Math.ceil(hours / 24));
  }

  function reservationDurationDays(start, end) {
    return (end.getTime() - start.getTime()) / 864e5;
  }

  function syncTimeOptions() {
    const currentMinutes = timeMinutes(currentTimeValue);
    const startMinutes = timeMinutes(startTime.value);
    const isStartToday = startDate.value === currentDateValue;
    const isEndToday = endDate.value === currentDateValue;
    const isSameTripDate = startDate.value && startDate.value === endDate.value;

    Array.from(startTime.options).forEach(function (option) {
      const optionMinutes = timeMinutes(option.value);
      option.disabled = isStartToday && currentMinutes !== null && optionMinutes !== null && optionMinutes < currentMinutes;
    });

    const nextStart = Array.from(startTime.options).find(function (option) {
      return !option.disabled;
    });

    if (!nextStart && isStartToday) {
      startDate.value = dateValue(addDays(today, 1));
      endDate.value = endDate.value < startDate.value ? startDate.value : endDate.value;
      syncTimeOptions();
      return;
    }

    if (startTime.selectedOptions[0] && startTime.selectedOptions[0].disabled && nextStart) {
      startTime.value = nextStart.value;
    }

    Array.from(endTime.options).forEach(function (option) {
      const optionMinutes = timeMinutes(option.value);
      const pastToday = isEndToday && currentMinutes !== null && optionMinutes !== null && optionMinutes < currentMinutes;
      const beforeStart = isSameTripDate && startMinutes !== null && optionMinutes !== null && optionMinutes <= startMinutes;
      option.disabled = pastToday || beforeStart;
    });

    const nextEnd = Array.from(endTime.options).find(function (option) {
      return !option.disabled;
    });

    if (!nextEnd && endDate.value) {
      endDate.value = dateValue(addDays(parseDateValue(endDate.value) || today, 1));
      syncTimeOptions();
      return;
    }

    if (endTime.selectedOptions[0] && endTime.selectedOptions[0].disabled && nextEnd) {
      endTime.value = nextEnd.value;
    }
  }

  function syncDateRules() {
    startDate.min = currentDateValue;

    if (startDate.value && startDate.value < currentDateValue) {
      startDate.value = currentDateValue;
    }

    const minPickup = startDate.value || currentDateValue;
    endDate.min = minPickup;

    if (!endDate.value || endDate.value < minPickup) {
      endDate.value = minPickup;
    }

    syncTimeOptions();
  }

  function requestRecaptchaToken() {
    return new Promise(function (resolve, reject) {
      if (!recaptchaSiteKey) {
        resolve('');
        return;
      }

      if (!window.grecaptcha || typeof window.grecaptcha.ready !== 'function') {
        reject(new Error('reCAPTCHA is not ready. Please try again.'));
        return;
      }

      window.grecaptcha.ready(function () {
        window.grecaptcha.execute(recaptchaSiteKey, { action: 'reservation_submit' })
          .then(resolve)
          .catch(function () {
            reject(new Error('reCAPTCHA verification failed. Please try again.'));
          });
      });
    });
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
    syncDateRules();
    updateEstimate();
    updateSummary();
    syncLotPills();
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
    if (recaptchaReadyToSubmit) {
      return;
    }

    event.preventDefault();

    for (let index = 0; index < steps.length; index += 1) {
      if (!validateStep(index)) {
        showStep(index);
        return;
      }
    }

    requestRecaptchaToken()
      .then(function (token) {
        if (recaptchaToken) {
          recaptchaToken.value = token;
        }

        recaptchaReadyToSubmit = true;
        form.submit();
      })
      .catch(function (error) {
        showStep(2);
        showStepError(2, error.message);
      });
  });

  lotPills.forEach(function (pill) {
    pill.addEventListener('click', function () {
      lotSelect.value = pill.dataset.lotPill;
      syncLotPills();
      updateEstimate();
    });
  });

  syncDateRules();
  syncLotPills();
  showStep(0);
  }
}());
