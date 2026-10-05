<?php

test('two workers submitting one approval-gated document create one ApprovalInstance')
    ->todo('Requires the real approval package and a multi-connection MySQL/PostgreSQL harness.');

test('two approval callbacks resume one document exactly once')
    ->todo('Sequential duplicate callback is covered; parallel row-lock coverage requires a real database.');
