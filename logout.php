<?php
session_start();
session_destroy();
header('Location: /nhatro/login.php');
exit;