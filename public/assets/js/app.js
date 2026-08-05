/*
 |----------------------------------------------------------------------
 | Venu365 -- application entry point
 |----------------------------------------------------------------------
 | Plain ES modules, no framework. Every behaviour is opt-in via a data-
 | attribute so server-rendered Blade stays the source of truth.
 */

import { initDropdowns } from './dropdown.js';
import { initModals, initConfirmations } from './modal.js';
import { initAlerts } from './alerts.js';
import { initHoursEditor } from './hours-editor.js';
import { initImageUpload } from './image-upload.js';
import { initBookingForm } from './booking-form.js';
import { initNotifications } from './notifications.js';
import { initScrollReveal } from './scroll-reveal.js';
import { initTheme } from './theme.js';
import { initSegmented } from './segmented.js';
import { initCountUp, initViewTransitions } from './motion.js';

function boot() {
    // Theme first: it only ever flips an attribute already rendered by
    // Blade, but doing it before anything measures layout avoids a
    // needless second style recalculation.
    initTheme();
    initViewTransitions();
    initCountUp();

    initDropdowns();
    initModals();
    initConfirmations();
    initAlerts();
    initHoursEditor();
    initImageUpload();
    // Before initBookingForm(): the segmented control has to be listening
    // before the first slot fetch can snap the duration underneath it.
    initSegmented();
    initBookingForm();
    initNotifications();
    initScrollReveal();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
