<?php
/** Local fixture only: matching theme closing markup and observed item count. */
defined( 'ABSPATH' ) || exit;
echo '</div><!-- exin-test-loop-count:' . esc_html( (string) wc_get_loop_prop( 'loop' ) ) . ' -->';
