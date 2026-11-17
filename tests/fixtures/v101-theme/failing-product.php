<?php
/** Local fixture only: simulate a theme error after loop state was established. */
defined( 'ABSPATH' ) || exit;
throw new RuntimeException( 'simulated_theme_template_failure' );
