<?php

use Packstub\SessionReplay\Tests\AssistTestCase;
use Packstub\SessionReplay\Tests\TestCase;
use Packstub\SessionReplay\Tests\ViewerDisabledTestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(ViewerDisabledTestCase::class)->in('ViewerDisabled');
pest()->extend(AssistTestCase::class)->in('Assist');
