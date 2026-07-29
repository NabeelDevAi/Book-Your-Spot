/*
 |----------------------------------------------------------------------
 | BookYourSpot -- application entry point
 |----------------------------------------------------------------------
 | Plain ES modules, no framework. Every behaviour is opt-in via a data-
 | attribute so server-rendered Blade stays the source of truth.
 */

import { initDropdowns } from './dropdown';
import { initModals, initConfirmations } from './modal';
import { initAlerts } from './alerts';
import { initHoursEditor } from './hours-editor';
import { initImageUpload } from './image-upload';
import { initBookingForm } from './booking-form';
import { initNotifications } from './notifications';
import { initScrollReveal } from './scroll-reveal';

function boot() {
    initDropdowns();
    initModals();
    initConfirmations();
    initAlerts();
    initHoursEditor();
    initImageUpload();
    initBookingForm();
    initNotifications();
    initScrollReveal();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
