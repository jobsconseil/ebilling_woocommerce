<?php
/*
 * pour être redigé de manière automatique au service E-Billing
 */

	if(isset($_GET['invoice_number']) && isset($_GET['eb_callbackurl'])){

		//$POST_URL = 'https://www.billing-easy.net';
		$POST_URL = 'http://sandbox.billing-easy.net';

		echo "<form action='" . $POST_URL . "' method='post' name='frm'>";
		echo "<input type='hidden' name='invoice_number' value='".$_GET['invoice_number']."'>";
		echo "<input type='hidden' name='eb_callbackurl' value='".$_GET['eb_callbackurl']."'>";
		echo "</form>";
		echo "<script language='JavaScript'>";
		echo "document.frm.submit();";
		echo "</script>";
	}