<?php

use App\Host\Data\TrafficStat;
use App\Traffic\TrafficJudge;
use App\Traffic\Verdict;

function served(int $requests, int $errors = 0, float $p95 = 100.0): TrafficStat
{
    return new TrafficStat($requests, $errors, $p95 / 2, $p95, $p95 * 2);
}

beforeEach(fn () => $this->judge = new TrafficJudge);

it('does not judge a release with too few requests', function () {
    expect($this->judge->judge(null, served(1000))->state)->toBe(Verdict::UNKNOWN)
        ->and($this->judge->judge(served(29, 29), served(1000))->state)->toBe(Verdict::UNKNOWN)
        ->and($this->judge->judge(served(29, 29), served(1000))->reason)->toContain('29 of 30');
});

it('says all is well when a release is no worse than the one before it', function () {
    $verdict = $this->judge->judge(served(500, 5), served(5000, 50));

    expect($verdict->isOk())->toBeTrue()->and($verdict->reason)->toContain('500 requests');
});

it('blames a release whose failure rate is clearly above the previous one', function () {
    $verdict = $this->judge->judge(served(200, 20), served(5000, 5)); // 10% against 0.1%

    expect($verdict->isBad())->toBeTrue()->and($verdict->reason)->toContain('10%')->toContain('0.1%');
});

it('does not blame a few failures, or a rate that is only a little higher', function () {
    expect($this->judge->judge(served(200, 4), served(5000, 0))->isOk())->toBeTrue()          // under 5 failures
        ->and($this->judge->judge(served(1000, 15), served(5000, 50))->isOk())->toBeTrue()    // 1.5% against 1%
        ->and($this->judge->judge(served(1000, 30), served(5000, 200))->isOk())->toBeTrue();  // 3% against 4%
});

it('needs both the margin and the factor', function () {
    // 3% against 1%: 2 points more and 3 times, so bad.
    expect($this->judge->judge(served(1000, 30), served(5000, 50))->isBad())->toBeTrue()
        // 11% against 9%: two points more but not twice as much, so fine.
        ->and($this->judge->judge(served(1000, 110), served(5000, 450))->isOk())->toBeTrue();
});

it('uses an absolute rate when there is nothing to compare with', function () {
    expect($this->judge->judge(served(200, 12), null)->isBad())->toBeTrue()                  // 6%
        ->and($this->judge->judge(served(200, 8), null)->isOk())->toBeTrue()                 // 4%
        ->and($this->judge->judge(served(200, 12), served(10, 0))->isBad())->toBeTrue();       // a reference with too few requests is none
});

it('blames a release that is much slower, but only with enough requests and a real reference', function () {
    expect($this->judge->judge(served(100, 0, 2000), served(1000, 0, 100))->isBad())->toBeTrue()
        ->and($this->judge->judge(served(100, 0, 2000), served(1000, 0, 100))->reason)->toContain('2000 ms')->toContain('100 ms')
        ->and($this->judge->judge(served(40, 0, 2000), served(1000, 0, 100))->isOk())->toBeTrue()        // too few for latency
        ->and($this->judge->judge(served(100, 0, 400), served(1000, 0, 100))->isOk())->toBeTrue()        // 4x but only 300 ms more
        ->and($this->judge->judge(served(100, 0, 2000), null)->isOk())->toBeTrue()                       // nothing to compare with
        ->and($this->judge->judge(served(100, 0, 600), served(1000, 0, 5))->isBad())->toBeTrue()          // a 5 ms reference: 600 ms is both 120 times and 595 ms more
        ->and($this->judge->judge(served(100, 0, 500), served(1000, 0, 5))->isOk())->toBeTrue();           // 500 ms is not 505 ms
});
