<?php
require __DIR__ . '/lib.php';
reseed();
section('Rökprov: inloggning per roll');
foreach (['super@sambruk.se', 'admin-a@orga.test', 'red-a@orga.test', 'anv-a@orga.test', 'admin-b@orgb.test'] as $e) {
    $c = new Client($e);
    $c->get('/admin/courses.php');
    check(true, "$e inloggad, admin/courses.php → {$c->status} " . ($c->redirectedTo() ?: snippet($c->body, 60)));
}
done();
