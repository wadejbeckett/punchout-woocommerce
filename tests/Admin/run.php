<?php
/** Focused standalone runner. */
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/Unit/AdminActionsTest.php';
$pass=0;$fail=0;
foreach((new ReflectionClass(AdminActionsTest::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m){if(!str_starts_with($m->name,'test'))continue;try{(new AdminActionsTest())->runBare($m->name);echo "PASS {$m->name}\n";$pass++;}catch(Throwable $e){echo "FAIL {$m->name}: {$e->getMessage()}\n";$fail++;}}
echo "Passed: $pass Failed: $fail\n";exit($fail?1:0);
