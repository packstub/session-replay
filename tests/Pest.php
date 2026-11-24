<?php

use Packstub\SessionReplay\Tests\AssistTestCase;
use Packstub\SessionReplay\Tests\StandaloneBearerTestCase;
use Packstub\SessionReplay\Tests\StandaloneTestCase;
use Packstub\SessionReplay\Tests\TestCase;
use Packstub\SessionReplay\Tests\ViewerDisabledTestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(ViewerDisabledTestCase::class)->in('ViewerDisabled');
pest()->extend(AssistTestCase::class)->in('Assist');
pest()->extend(StandaloneTestCase::class)->in('Standalone');
pest()->extend(StandaloneBearerTestCase::class)->in('StandaloneBearer');
