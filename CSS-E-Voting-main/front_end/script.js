document.querySelectorAll('form').forEach((form) => {
	form.noValidate = true;
});

const validationDialog = document.createElement('dialog');
validationDialog.className = 'confirmation-dialog validation-dialog';
validationDialog.setAttribute('aria-labelledby', 'validation-title');
validationDialog.setAttribute('aria-describedby', 'validation-message');
validationDialog.innerHTML = `
	<div class="confirmation-card">
		<span class="confirmation-icon" aria-hidden="true">!</span>
		<h2 id="validation-title">Check your information</h2>
		<p id="validation-message"></p>
		<div class="confirmation-actions">
			<button class="confirmation-cancel" type="button">Got it</button>
		</div>
	</div>
`;
document.body.append(validationDialog);

let invalidFieldToFocus = null;

function showValidationMessage(message, field) {
	invalidFieldToFocus = field;
	validationDialog.querySelector('#validation-message').textContent = message;
	validationDialog.showModal();
	validationDialog.querySelector('.confirmation-cancel').focus();
}

function closeValidationDialog() {
	const field = invalidFieldToFocus;
	invalidFieldToFocus = null;
	validationDialog.close();
	if (field instanceof HTMLElement && field.isConnected) {
		window.setTimeout(() => field.focus(), 0);
	}
}

validationDialog.querySelector('.confirmation-cancel').addEventListener('click', closeValidationDialog);
validationDialog.addEventListener('cancel', (event) => {
	event.preventDefault();
	closeValidationDialog();
});

function fieldLabel(field) {
	if (field.dataset.validationLabel) {
		return field.dataset.validationLabel;
	}
	const label = field.labels?.[0];
	const labelText = label ? label.textContent.replace('*', '').trim() : '';
	return labelText || 'This field';
}

function fieldError(field) {
	const name = fieldLabel(field);
	const value = typeof field.value === 'string' ? field.value : '';

	if (field.required) {
		if (field.type === 'radio') {
			const radioGroup = Array.from(field.form.elements).filter((item) => item instanceof HTMLInputElement && item.type === 'radio' && item.name === field.name);
			if (!radioGroup.some((radio) => radio.checked)) {
				return `Choose an option for ${name.toLowerCase()}.`;
			}
		} else if (field.type === 'checkbox' && !field.checked) {
			return `Please check ${name.toLowerCase()} to continue.`;
		} else if (field.type !== 'checkbox' && value.trim() === '') {
			return `Please complete ${name.toLowerCase()}.`;
		}
	}

	if (field.validity.typeMismatch) {
		return field.type === 'email' ? 'Enter a valid email address.' : `Enter a valid ${name.toLowerCase()}.`;
	}
	if (field.validity.patternMismatch) {
		return `Check the format for ${name.toLowerCase()}.`;
	}
	if (field.validity.tooShort) {
		return `${name} must be at least ${field.minLength} characters.`;
	}
	if (field.validity.tooLong) {
		return `${name} must be no more than ${field.maxLength} characters.`;
	}
	if (field.validity.rangeUnderflow || field.validity.rangeOverflow) {
		return `${name} must be within the allowed range.`;
	}
	if (field.validity.stepMismatch || field.validity.badInput) {
		return `Enter a valid value for ${name.toLowerCase()}.`;
	}

	if (field.name === 'confirm_password') {
		const password = field.form.elements.namedItem('new_password') || field.form.elements.namedItem('password');
		if (password instanceof HTMLInputElement && value !== password.value) {
			return 'Your password and confirmation do not match.';
		}
	}

	return '';
}

function validateForm(form) {
	const fields = Array.from(form.querySelectorAll('input, select, textarea'))
		.filter((field) => !field.disabled && (field.type !== 'hidden' || field.matches('.admin-date-picker-value')));
	let firstInvalidField = null;
	let firstError = '';

	fields.forEach((field) => {
		const error = fieldError(field);
		const datePickerTrigger = field.matches('.admin-date-picker-value')
			? form.querySelector(`[data-date-target="${CSS.escape(field.id)}"]`)
			: null;
		if (error) {
			field.setAttribute('aria-invalid', 'true');
			datePickerTrigger?.setAttribute('aria-invalid', 'true');
			if (!firstInvalidField) {
				firstInvalidField = field;
				firstError = error;
			}
		} else {
			field.removeAttribute('aria-invalid');
			datePickerTrigger?.removeAttribute('aria-invalid');
		}
	});

	if (firstInvalidField) {
		const datePickerTrigger = firstInvalidField.matches('.admin-date-picker-value')
			? form.querySelector(`[data-date-target="${CSS.escape(firstInvalidField.id)}"]`)
			: null;
		return { field: datePickerTrigger || firstInvalidField, message: firstError };
	}

	const startDate = form.elements.namedItem('start_date');
	const endDate = form.elements.namedItem('end_date');
	if (startDate instanceof HTMLInputElement && endDate instanceof HTMLInputElement && startDate.value && endDate.value
		&& new Date(startDate.value) >= new Date(endDate.value)) {
		endDate.setAttribute('aria-invalid', 'true');
		const endDateTrigger = form.querySelector(`[data-date-target="${CSS.escape(endDate.id)}"]`);
		endDateTrigger?.setAttribute('aria-invalid', 'true');
		return { field: endDateTrigger || endDate, message: 'The end date and time must be later than the start date and time.' };
	}

	return null;
}

const dateTimeDialog = document.createElement('dialog');
dateTimeDialog.className = 'date-time-dialog';
dateTimeDialog.setAttribute('aria-labelledby', 'date-time-title');
dateTimeDialog.innerHTML = `
	<div class="date-time-card">
		<header class="date-time-header">
			<div><span class="date-time-eyebrow">ELECTION SCHEDULE</span><h2 id="date-time-title">Choose date and time</h2></div>
			<button class="date-time-close" type="button" aria-label="Close date and time picker"><i class="ti ti-x" aria-hidden="true"></i></button>
		</header>
		<div class="date-time-selected"><i class="ti ti-calendar-event" aria-hidden="true"></i><span></span></div>
		<div class="date-time-month">
			<strong></strong>
			<div><button type="button" data-month-shift="-1" aria-label="Previous month"><i class="ti ti-chevron-left" aria-hidden="true"></i></button><button type="button" data-month-shift="1" aria-label="Next month"><i class="ti ti-chevron-right" aria-hidden="true"></i></button></div>
		</div>
		<div class="date-time-weekdays" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
		<div class="date-time-days" role="grid" aria-label="Choose a date"></div>
		<section class="date-time-clock" aria-label="Choose a time">
			<div><span class="date-time-section-label">VOTING TIME</span><strong>Select the time voting begins or ends</strong></div>
			<div class="date-time-time-controls">
				<label>Hour<select class="date-time-hour" aria-label="Hour"></select></label>
				<span aria-hidden="true">:</span>
				<label>Minute<select class="date-time-minute" aria-label="Minute"></select></label>
				<div class="date-time-period" role="group" aria-label="AM or PM"><button type="button" data-period="AM">AM</button><button type="button" data-period="PM">PM</button></div>
			</div>
		</section>
		<footer class="date-time-actions"><button class="date-time-cancel" type="button">Cancel</button><button class="date-time-apply" type="button"><i class="ti ti-check" aria-hidden="true"></i> Apply date &amp; time</button></footer>
	</div>
`;
document.body.append(dateTimeDialog);

let activeDateInput = null;
let datePickerTrigger = null;
let selectedDate = new Date();
let visibleMonth = new Date();

function formatDateTime(date) {
	return new Intl.DateTimeFormat(undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	}).format(date);
}

function inputDateTime(value) {
	const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
	if (!match) {
		return null;
	}
	return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]));
}

function selectedTime() {
	const hour12 = Number(dateTimeDialog.querySelector('.date-time-hour').value);
	const minute = Number(dateTimeDialog.querySelector('.date-time-minute').value);
	const period = dateTimeDialog.querySelector('.date-time-period button[aria-pressed="true"]')?.dataset.period || 'AM';
	const hour24 = (hour12 % 12) + (period === 'PM' ? 12 : 0);
	return { hour24, minute };
}

function updateSelectedDateLabel() {
	const { hour24, minute } = selectedTime();
	const preview = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), selectedDate.getDate(), hour24, minute);
	dateTimeDialog.querySelector('.date-time-selected span').textContent = formatDateTime(preview);
}

function renderDatePicker() {
	const monthLabel = dateTimeDialog.querySelector('.date-time-month strong');
	const daysContainer = dateTimeDialog.querySelector('.date-time-days');
	const year = visibleMonth.getFullYear();
	const month = visibleMonth.getMonth();
	monthLabel.textContent = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(visibleMonth);
	daysContainer.replaceChildren();

	const firstDay = new Date(year, month, 1).getDay();
	const dayCount = new Date(year, month + 1, 0).getDate();
	const today = new Date();
	today.setHours(0, 0, 0, 0);
	for (let index = 0; index < firstDay; index += 1) {
		const spacer = document.createElement('span');
		spacer.className = 'date-time-day-spacer';
		spacer.setAttribute('aria-hidden', 'true');
		daysContainer.append(spacer);
	}
	for (let day = 1; day <= dayCount; day += 1) {
		const date = new Date(year, month, day);
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'date-time-day';
		button.textContent = String(day);
		button.dataset.date = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
		button.setAttribute('role', 'gridcell');
		button.setAttribute('aria-label', new Intl.DateTimeFormat(undefined, { dateStyle: 'full' }).format(date));
		button.setAttribute('aria-selected', String(date.toDateString() === selectedDate.toDateString()));
		button.disabled = date < today;
		if (date.toDateString() === today.toDateString()) {
			button.classList.add('is-today');
		}
		if (date.toDateString() === selectedDate.toDateString()) {
			button.classList.add('is-selected');
		}
		daysContainer.append(button);
	}
	updateSelectedDateLabel();
}

function setSelectedTime(date) {
	const hour24 = date.getHours();
	dateTimeDialog.querySelector('.date-time-hour').value = String(hour24 % 12 || 12);
	dateTimeDialog.querySelector('.date-time-minute').value = String(date.getMinutes()).padStart(2, '0');
	dateTimeDialog.querySelectorAll('.date-time-period button').forEach((button) => {
		button.setAttribute('aria-pressed', String(button.dataset.period === (hour24 >= 12 ? 'PM' : 'AM')));
	});
}

function closeDatePicker() {
	dateTimeDialog.close();
	if (datePickerTrigger?.isConnected) {
		datePickerTrigger.focus();
	}
	activeDateInput = null;
	datePickerTrigger = null;
}

dateTimeDialog.querySelectorAll('.date-time-hour, .date-time-minute').forEach((select) => {
	const maximum = select.classList.contains('date-time-hour') ? 12 : 59;
	for (let number = select.classList.contains('date-time-hour') ? 1 : 0; number <= maximum; number += 1) {
		const option = document.createElement('option');
		option.value = String(number);
		option.textContent = String(number).padStart(2, '0');
		select.append(option);
	}
	select.addEventListener('change', updateSelectedDateLabel);
});

document.addEventListener('click', (event) => {
	const trigger = event.target instanceof Element ? event.target.closest('.admin-date-picker-trigger') : null;
	if (trigger instanceof HTMLButtonElement) {
		const input = document.getElementById(trigger.dataset.dateTarget || '');
		if (!(input instanceof HTMLInputElement) || !input.matches('.admin-date-picker-value')) {
			return;
		}
		activeDateInput = input;
		datePickerTrigger = trigger;
		const savedDate = inputDateTime(input.value);
		selectedDate = savedDate || new Date(Date.now() + 60 * 60 * 1000);
		if (!savedDate) {
			selectedDate.setSeconds(0, 0);
		}
		visibleMonth = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
		setSelectedTime(selectedDate);
		renderDatePicker();
		dateTimeDialog.showModal();
		dateTimeDialog.querySelector('.date-time-apply').focus();
	}
});

dateTimeDialog.addEventListener('click', (event) => {
	const target = event.target;
	if (!(target instanceof Element)) {
		return;
	}
	const monthButton = target.closest('[data-month-shift]');
	if (monthButton instanceof HTMLButtonElement) {
		visibleMonth.setMonth(visibleMonth.getMonth() + Number(monthButton.dataset.monthShift));
		renderDatePicker();
		return;
	}
	const dayButton = target.closest('.date-time-day');
	if (dayButton instanceof HTMLButtonElement && !dayButton.disabled) {
		const [year, month, day] = dayButton.dataset.date.split('-').map(Number);
		selectedDate.setFullYear(year, month - 1, day);
		renderDatePicker();
		return;
	}
	const periodButton = target.closest('[data-period]');
	if (periodButton instanceof HTMLButtonElement) {
		dateTimeDialog.querySelectorAll('.date-time-period button').forEach((button) => {
			button.setAttribute('aria-pressed', String(button === periodButton));
		});
		updateSelectedDateLabel();
	}
});

dateTimeDialog.querySelector('.date-time-close').addEventListener('click', closeDatePicker);
dateTimeDialog.querySelector('.date-time-cancel').addEventListener('click', closeDatePicker);
dateTimeDialog.addEventListener('cancel', (event) => {
	event.preventDefault();
	closeDatePicker();
});
dateTimeDialog.querySelector('.date-time-apply').addEventListener('click', () => {
	if (!activeDateInput || !datePickerTrigger) {
		return;
	}
	const { hour24, minute } = selectedTime();
	const value = `${selectedDate.getFullYear()}-${String(selectedDate.getMonth() + 1).padStart(2, '0')}-${String(selectedDate.getDate()).padStart(2, '0')}T${String(hour24).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
	activeDateInput.value = value;
	activeDateInput.removeAttribute('aria-invalid');
	datePickerTrigger.removeAttribute('aria-invalid');
	datePickerTrigger.querySelector('span').textContent = formatDateTime(new Date(selectedDate.getFullYear(), selectedDate.getMonth(), selectedDate.getDate(), hour24, minute));
	activeDateInput.dispatchEvent(new Event('input', { bubbles: true }));
	activeDateInput.dispatchEvent(new Event('change', { bubbles: true }));
	closeDatePicker();
});

document.querySelectorAll('.admin-date-picker-trigger').forEach((trigger) => {
	const input = document.getElementById(trigger.dataset.dateTarget || '');
	const date = input instanceof HTMLInputElement ? inputDateTime(input.value) : null;
	if (date) {
		trigger.querySelector('span').textContent = formatDateTime(date);
	}
});

document.querySelectorAll('[data-voter-search]').forEach((searchContainer) => {
	const searchInput = searchContainer.querySelector('input[type="search"]');
	const accountInput = searchContainer.querySelector('input[name="voter_account_id"]');
	const results = searchContainer.querySelector('[role="listbox"]');
	const status = searchContainer.querySelector('[data-search-status]');
	if (!(searchInput instanceof HTMLInputElement) || !(accountInput instanceof HTMLInputElement)
		|| !(results instanceof HTMLElement) || !(status instanceof HTMLElement)) {
		return;
	}

	let searchTimer = 0;
	let requestController = null;
	let selectedLabel = searchInput.value;
	if (accountInput.value === '') {
		selectedLabel = '';
	}

	const closeResults = () => {
		results.hidden = true;
		searchInput.setAttribute('aria-expanded', 'false');
	};

	searchInput.addEventListener('input', () => {
		window.clearTimeout(searchTimer);
		if (requestController) {
			requestController.abort();
			requestController = null;
		}
		if (searchInput.value !== selectedLabel) {
			accountInput.value = '';
			selectedLabel = '';
		}

		const query = searchInput.value.trim();
		results.replaceChildren();
		closeResults();
		if (query.length < 2) {
			status.textContent = 'Type at least 2 characters to search active voter accounts.';
			return;
		}

		status.textContent = 'Searching voter accounts…';
		searchTimer = window.setTimeout(async () => {
			requestController = new AbortController();
			const controller = requestController;
			const url = new URL(window.location.href);
			url.searchParams.set('ajax', 'voter-search');
			url.searchParams.set('q', query);
			try {
				const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
				if (!response.ok) {
					throw new Error(`Voter search failed with status ${response.status}.`);
				}
				const data = await response.json();
				if (controller !== requestController) {
					return;
				}
				if (!Array.isArray(data.results)) {
					throw new Error('Voter search returned an invalid response.');
				}
				if (data.results.length === 0) {
					status.textContent = 'No active voter accounts matched that name or student ID.';
					return;
				}

				data.results.forEach((voter) => {
					if (!Number.isInteger(Number(voter.account_id)) || typeof voter.fullname !== 'string' || typeof voter.student_id !== 'string') {
						return;
					}
					const option = document.createElement('button');
					option.type = 'button';
					option.className = 'admin-voter-search-option';
					option.setAttribute('role', 'option');
					const name = document.createElement('strong');
					name.textContent = voter.fullname;
					const studentId = document.createElement('span');
					studentId.textContent = voter.student_id;
					option.append(name, studentId);
					option.addEventListener('click', () => {
						accountInput.value = String(voter.account_id);
						selectedLabel = `${voter.fullname} — ${voter.student_id}`;
						searchInput.value = selectedLabel;
						status.textContent = 'Selected voter account';
						results.replaceChildren();
						closeResults();
					});
					results.append(option);
				});
				if (results.childElementCount > 0) {
					results.hidden = false;
					searchInput.setAttribute('aria-expanded', 'true');
					status.textContent = 'Select the correct voter account from the results.';
				} else {
					status.textContent = 'No active voter accounts matched that name or student ID.';
				}
			} catch (error) {
				if (error instanceof DOMException && error.name === 'AbortError') {
					return;
				}
				console.error(error);
				status.textContent = 'Unable to search voter accounts right now. Please try again.';
			}
		}, 250);
	});

	searchInput.addEventListener('keydown', (event) => {
		if (event.key === 'ArrowDown' && !results.hidden) {
			event.preventDefault();
			results.querySelector('button')?.focus();
		} else if (event.key === 'Escape') {
			closeResults();
		}
	});
});

document.addEventListener('submit', (event) => {
	const form = event.target;
	if (!(form instanceof HTMLFormElement)) {
		return;
	}

	if (form.matches('.candidate-account-form')) {
		const selectedAccount = form.querySelector('input[name="voter_account_id"]');
		const searchInput = form.querySelector('input[type="search"]');
		if (!(selectedAccount instanceof HTMLInputElement) || selectedAccount.value === '') {
			event.preventDefault();
			if (searchInput instanceof HTMLInputElement) {
				showValidationMessage('Search for and select an active voter account before adding the candidate.', searchInput);
			}
			return;
		}
	}

	const invalid = validateForm(form);
	if (invalid) {
		event.preventDefault();
		showValidationMessage(invalid.message, invalid.field);
		return;
	}

	if (form.dataset.confirmedOnce === 'true') {
		delete form.dataset.confirmedOnce;
		return;
	}

	if (!form.dataset.confirmMessage) {
		return;
	}

	event.preventDefault();
	pendingConfirmation = {
		form,
		submitter: event.submitter instanceof HTMLElement ? event.submitter : null,
	};

	confirmationDialog.querySelector('#confirmation-title').textContent = form.dataset.confirmTitle || 'Please confirm';
	confirmationDialog.querySelector('#confirmation-message').textContent = form.dataset.confirmMessage;
	confirmationDialog.querySelector('.confirmation-accept').textContent = form.dataset.confirmAction || 'Confirm';
	confirmationDialog.showModal();
	confirmationDialog.querySelector('.confirmation-cancel').focus();
});

document.addEventListener('input', (event) => {
	if (event.target instanceof HTMLElement && event.target.matches('input, select, textarea')) {
		event.target.removeAttribute('aria-invalid');
	}
});

document.addEventListener('change', (event) => {
	if (event.target instanceof HTMLElement && event.target.matches('input, select, textarea')) {
		event.target.removeAttribute('aria-invalid');
	}
});

const confirmationDialog = document.createElement('dialog');
confirmationDialog.className = 'confirmation-dialog';
confirmationDialog.setAttribute('aria-labelledby', 'confirmation-title');
confirmationDialog.setAttribute('aria-describedby', 'confirmation-message');
confirmationDialog.innerHTML = `
	<div class="confirmation-card">
		<span class="confirmation-icon" aria-hidden="true">!</span>
		<h2 id="confirmation-title"></h2>
		<p id="confirmation-message"></p>
		<div class="confirmation-actions">
			<button class="confirmation-cancel" type="button">Cancel</button>
			<button class="confirmation-accept" type="button"></button>
		</div>
	</div>
`;
document.body.append(confirmationDialog);

let pendingConfirmation = null;

function closeConfirmation(confirmed) {
	if (!pendingConfirmation) {
		return;
	}

	const { form, submitter } = pendingConfirmation;
	pendingConfirmation = null;
	confirmationDialog.close();

	if (confirmed) {
		form.dataset.confirmedOnce = 'true';
		form.requestSubmit(submitter || undefined);
	} else if (submitter instanceof HTMLElement) {
		submitter.focus();
	}
}

confirmationDialog.querySelector('.confirmation-cancel').addEventListener('click', () => closeConfirmation(false));
confirmationDialog.querySelector('.confirmation-accept').addEventListener('click', () => closeConfirmation(true));
confirmationDialog.addEventListener('cancel', (event) => {
	event.preventDefault();
	closeConfirmation(false);
});
