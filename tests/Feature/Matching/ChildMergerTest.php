<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Matching;

use IntrAgile\LaravelSpreadsheet\Matching\ChildMergeOutcome;
use IntrAgile\LaravelSpreadsheet\Matching\ChildMerger;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Contact;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class ChildMergerTest extends TestCase
{
    private Partner $partner;

    private ChildMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create(['name' => 'P']);
        $this->merger = new ChildMerger;
    }

    /** @param  array<string, mixed>  $attributes */
    private function merge(array $attributes): ChildMergeOutcome
    {
        return $this->merger->merge($this->partner->contacts(), $attributes, ['email' => 'email', 'phone' => 'phone']);
    }

    public function test_creates_when_nothing_matches(): void
    {
        $this->assertSame(ChildMergeOutcome::Added, $this->merge(['name' => 'A', 'email' => 'a@x.hu', 'phone' => '+36 1']));
        $this->assertSame(1, $this->partner->contacts()->count());
    }

    public function test_email_match_is_case_insensitive_and_fills_blanks_only(): void
    {
        $this->partner->contacts()->create(['name' => 'Old', 'email' => 'a@x.hu']);

        $outcome = $this->merge(['name' => 'New', 'email' => 'A@X.HU', 'phone' => '+36 1']);

        $this->assertSame(ChildMergeOutcome::Filled, $outcome);
        $contact = Contact::query()->sole();
        $this->assertSame('Old', $contact->name);
        $this->assertSame('+36 1', $contact->phone);
    }

    public function test_never_overwrites_existing_values(): void
    {
        $this->partner->contacts()->create(['name' => 'Old', 'email' => 'a@x.hu', 'phone' => '111']);

        $this->assertSame(ChildMergeOutcome::Unchanged, $this->merge(['name' => 'New', 'email' => 'a@x.hu', 'phone' => '222']));
        $this->assertSame('111', Contact::query()->sole()->phone);
    }

    public function test_phone_only_row_matches_a_child_with_blank_email(): void
    {
        $this->partner->contacts()->create(['name' => 'Old', 'email' => null, 'phone' => '+36 30 111-2222']);

        $outcome = $this->merge(['name' => 'X', 'email' => '', 'phone' => '36301112222']);

        $this->assertSame(ChildMergeOutcome::Unchanged, $outcome);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_phone_only_row_never_matches_a_child_that_has_an_email(): void
    {
        $this->partner->contacts()->create(['name' => 'Old', 'email' => 'a@x.hu', 'phone' => '111222']);

        $outcome = $this->merge(['name' => 'X', 'phone' => '111222']);

        $this->assertSame(ChildMergeOutcome::Added, $outcome);
        $this->assertSame(2, Contact::query()->count());
    }

    public function test_remerge_is_unchanged(): void
    {
        $row = ['name' => 'A', 'email' => 'a@x.hu', 'phone' => '111222'];

        $this->assertSame(ChildMergeOutcome::Added, $this->merge($row));
        $this->assertSame(ChildMergeOutcome::Unchanged, $this->merge($row));
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_row_without_keys_is_unchanged_and_creates_nothing(): void
    {
        $this->assertSame(ChildMergeOutcome::Unchanged, $this->merge(['name' => 'A']));
        $this->assertSame(0, Contact::query()->count());
    }
}
