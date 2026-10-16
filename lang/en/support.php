<?php

/*
| One prefilled WhatsApp message per place the link appears. Placeholders are
| filled from page names, the hospital's name, the person's role, an error
| reference or a version, never from a patient or a record.
*/

return [

    'site.general' => 'Hello True-Doctor, I found you on your website and I would like to know more.',
    'site.page' => 'Hello True-Doctor, I am reading your page about :page and I have a question.',
    'site.pricing' => 'Hello True-Doctor, I would like help choosing a plan for my :practice.',
    'site.demo' => 'Hello True-Doctor, I opened the demo and I would like a walkthrough.',
    'site.own_copy' => 'Hello True-Doctor, I am interested in buying our own copy of True-Doctor.',
    'auth.signin' => 'Hello True-Doctor, I cannot sign in to :hospital.',
    'trial.welcome' => 'Hello True-Doctor, I have just started a trial for :hospital. Please help me set up.',
    'admin.help' => 'Hello True-Doctor, I am :role at :hospital and I need help with :screen.',
    'admin.error' => 'Hello True-Doctor, I got an error at :hospital. Reference :ref.',
    'admin.limit' => 'Hello True-Doctor, :hospital has reached the :limit limit on our plan.',
    'admin.renew' => 'Hello True-Doctor, I would like to renew the subscription for :hospital.',
    'app.help' => 'Hello True-Doctor, I need help with the app (version :version, :role at :hospital).',
    'app.diagnostics' => 'Hello True-Doctor, here is my app diagnostics report: :report',
    'standalone.install' => 'Hello True-Doctor, I need help installing our copy (licence :licence).',
    'standalone.update' => 'Hello True-Doctor, our copy :version needs help with an update.',
];
