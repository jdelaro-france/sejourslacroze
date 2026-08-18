<?php
require __DIR__ . '/bootstrap.php';
logout();
// Nouvelle session propre pour transporter le message de confirmation.
session_start();
flash_set('Vous êtes déconnecté.');
redirect('login.php');
