<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/batch:manage' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
);
