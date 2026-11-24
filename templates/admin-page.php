<?php
/**
 * Admin page — S1 surface (SLICE-262-13).
 * Two tabs: Scan (free local, always available) and Cloud (stub
 * until 262-14 connects the site_key channel).
 *
 * Honesty contract: local results are self-observed — the page
 * says so; `unknown` renders as its own state, never as pass.
 *
 * @package agentbadge
 * @var array<string,mixed> $view View data from the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ab_tab   = $view['tab'] ?? 'scan';
$scan     = $view['scan'] ?? null;
$results  = $scan['results'] ?? array();
$ran_at   = $scan['scanned_at'] ?? null;
$statuses = array(
	'pass'    => 0,
	'warn'    => 0,
	'fail'    => 0,
	'unknown' => 0,
);
foreach ( $results as $r ) {
	$statuses[ $r['status'] ] = ( $statuses[ $r['status'] ] ?? 0 ) + 1;
}
$axes = array(
	'readiness' => __( 'Agent Readiness — can agents find and read your site', 'agentbadge' ),
	'exposure'  => __( 'Agent Surface Exposure — what is needlessly open', 'agentbadge' ),
);
$chip = array(
	'pass'    => array( __( 'Good', 'agentbadge' ), '#00a32a' ),
	'warn'    => array( __( 'Recommended', 'agentbadge' ), '#dba617' ),
	'fail'    => array( __( 'Critical', 'agentbadge' ), '#d63638' ),
	'unknown' => array( __( 'Unknown', 'agentbadge' ), '#787c82' ),
);
?>
<div class="wrap agentbadge-admin">
	<h1><?php echo esc_html__( 'AgentBadge — Agent Readiness', 'agentbadge' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentbadge&tab=scan' ) ); ?>"
			class="nav-tab <?php echo 'scan' === $ab_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Scan', 'agentbadge' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentbadge&tab=cloud' ) ); ?>"
			class="nav-tab <?php echo 'cloud' === $ab_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Cloud', 'agentbadge' ); ?>
		</a>
	</nav>

	<?php if ( 'scan' === $ab_tab ) : ?>
		<div class="agentbadge-scan">
			<p class="description">
				<?php esc_html_e( 'Free local scanner: everything below is observed by this plugin inside WordPress — no external requests, no account. Self-observed results are a starting point, not independent verification.', 'agentbadge' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0;">
				<input type="hidden" name="action" value="agentbadge_scan" />
				<?php wp_nonce_field( 'agentbadge_scan' ); ?>
				<button type="submit" class="button button-primary">
					<?php echo esc_html( $ran_at ? __( 'Run scan again', 'agentbadge' ) : __( 'Run scan', 'agentbadge' ) ); ?>
				</button>
				<?php if ( $ran_at ) : ?>
					<span class="description" style="margin-left:8px;">
						<?php
						/* translators: %s: scan timestamp */
						printf( esc_html__( 'Last scan: %s', 'agentbadge' ), esc_html( $ran_at ) );
						?>
					</span>
				<?php endif; ?>
			</form>

			<?php if ( $results ) : ?>
				<p>
					<?php foreach ( $chip as $st => $c ) : ?>
						<span class="agentbadge-chip" style="border-left:4px solid <?php echo esc_attr( $c[1] ); ?>;padding:2px 8px;margin-right:10px;background:#f6f7f7;">
							<?php echo esc_html( $c[0] ) . ': ' . (int) $statuses[ $st ]; ?>
						</span>
					<?php endforeach; ?>
				</p>

				<?php foreach ( $axes as $axis => $axis_label ) : ?>
					<h2><?php echo esc_html( $axis_label ); ?></h2>
					<table class="widefat striped" style="max-width:900px;">
						<tbody>
						<?php foreach ( $results as $r ) : ?>
							<?php
							if ( $r['axis'] !== $axis ) {
								continue; }
							?>
							<tr>
								<td style="width:140px;">
									<span class="agentbadge-chip" style="border-left:4px solid <?php echo esc_attr( $chip[ $r['status'] ][1] ); ?>;padding:2px 8px;background:#f6f7f7;">
										<?php echo esc_html( $chip[ $r['status'] ][0] ); ?>
									</span>
								</td>
								<td style="width:220px;"><strong><?php echo esc_html( $r['title'] ); ?></strong></td>
								<td>
									<?php echo esc_html( $r['reason'] ); ?>
									<?php if ( '' !== $r['hint'] ) : ?>
										<br /><span class="description"><?php echo esc_html( $r['hint'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<p class="description" style="margin-top:12px;">
					<?php esc_html_e( 'Unknown means the plugin could not determine the fact locally — it is not a pass. External verification of these facts is what AgentBadge Cloud adds.', 'agentbadge' ); ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No scan yet — run your first local scan. It takes under a second and makes no external calls.', 'agentbadge' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>
		<div class="agentbadge-cloud" style="max-width:720px;">
			<h2><?php esc_html_e( 'AgentBadge Cloud', 'agentbadge' ); ?></h2>
			<p>
				<?php esc_html_e( 'Cloud adds what a plugin cannot see from inside: independent external observation (what the site actually returns through your CDN/WAF), signed reports, history and monthly deltas.', 'agentbadge' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Site connection (site_key handshake + self-report channel) arrives in an upcoming release. Nothing is sent anywhere until you connect a key — the free scanner above makes no external calls.', 'agentbadge' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>
