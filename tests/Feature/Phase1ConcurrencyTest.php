<?php

test('two workers cannot consume the same FIFO layer quantity')->todo('Requires a multi-connection MySQL/PostgreSQL concurrency harness.');
test('two workers cannot over-consume one reservation')->todo('Requires a multi-connection MySQL/PostgreSQL concurrency harness.');
test('two workers cannot post the same negative-stock issue inconsistently')->todo('Requires a multi-connection MySQL/PostgreSQL concurrency harness.');
test('two workers cannot create conflicting Stock Card rows')->todo('Requires a multi-connection MySQL/PostgreSQL concurrency harness.');
test('two workers resuming one approved document post it exactly once')->todo('Sequential duplicate delivery is covered; parallel worker coverage requires a real database.');
