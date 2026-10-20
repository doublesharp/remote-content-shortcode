<?php
/** Generate certificates for the isolated Docker fixture only. */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
$directory = '/work/certificates';
if ( ! is_dir( $directory ) ) {
	mkdir( $directory, 0700, true );
}
$config = $directory . '/openssl.cnf';
file_put_contents( $config, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:source.rcs.test,DNS:rebind.rcs.test,DNS:untrusted.rcs.test\n" );
$options = array( 'config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048 );
$ca_key = openssl_pkey_new( $options );
$ca_csr = openssl_csr_new( array( 'commonName' => 'RCS isolated test CA' ), $ca_key, $options );
$ca = openssl_csr_sign( $ca_csr, null, $ca_key, 2, $options + array( 'x509_extensions' => 'ca' ) );
openssl_x509_export_to_file( $ca, $directory . '/ca.pem' );
foreach ( array( 'server', 'untrusted' ) as $name ) {
	$key = openssl_pkey_new( $options );
	$csr = openssl_csr_new( array( 'commonName' => 'source.rcs.test' ), $key, $options );
	$certificate = openssl_csr_sign( $csr, 'server' === $name ? $ca : null, 'server' === $name ? $ca_key : $key, 2, $options + array( 'x509_extensions' => 'server' ) );
	openssl_pkey_export_to_file( $key, $directory . '/' . $name . '.key', null, $options );
	openssl_x509_export_to_file( $certificate, $directory . '/' . $name . '.pem' );
	chmod( $directory . '/' . $name . '.key', 0600 );
}
echo "Generated isolated test certificates.\n";
