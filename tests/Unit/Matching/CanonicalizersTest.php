<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Matching;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Matching\CanonicalizerRegistry;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\DomainCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\EmailCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\PhoneCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\TaxNumberCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\TextCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

final class CanonicalizersTest extends TestCase
{
    public function test_email(): void
    {
        $c = new EmailCanonicalizer;

        $this->assertSame('ekezetes-teszt@example.test', $c->canonicalize('  EKEZETES-TESZT@EXAMPLE.TEST '));
        $this->assertNull($c->canonicalize(''));
        $this->assertNull($c->canonicalize(null));
    }

    public function test_phone(): void
    {
        $c = new PhoneCanonicalizer;

        $this->assertSame('36301112222', $c->canonicalize('+36 (30) 111-2222'));
        $this->assertNull($c->canonicalize('abc'));
    }

    public function test_tax_number(): void
    {
        $c = new TaxNumberCanonicalizer;

        $this->assertSame('12345678241', $c->canonicalize('12345678-2-41'));
        $this->assertSame('de123456789', $c->canonicalize('DE 123.456/789'));
        $this->assertNull($c->canonicalize(' - '));
    }

    public function test_text(): void
    {
        $c = new TextCanonicalizer;

        $this->assertSame('kovacs bela', $c->canonicalize('  Kovács   Béla '));
        $this->assertNull($c->canonicalize('   '));
    }

    /** @return array<string, array{string, string}> */
    public static function domains(): array
    {
        return [
            'full url' => ['https://WWW.Pelda.hu:443/x?y', 'pelda.hu'],
            'trailing dot' => ['pelda.hu.', 'pelda.hu'],
            'subdomain unchanged' => ['sub.pelda.hu', 'sub.pelda.hu'],
            'padded' => [' https://www.Hatvan.hu:443/kapcsolat/ ', 'hatvan.hu'],
            'upper scheme' => ['HTTP://pont32-fogaszat.hu', 'pont32-fogaszat.hu'],
            'query without path' => ['pelda.hu?utm=1', 'pelda.hu'],
            'fragment without path' => ['pelda.hu#top', 'pelda.hu'],
            'credentials' => ['https://user:pw@pelda.hu/', 'pelda.hu'],
            'one www only' => ['www.www.x.hu', 'www.x.hu'],
        ];
    }

    #[DataProvider('domains')]
    public function test_domain_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainCanonicalizer::normalize($input));
        $this->assertSame($expected, (new DomainCanonicalizer)->canonicalize($input));
    }

    #[RequiresPhpExtension('intl')]
    public function test_domain_idn(): void
    {
        $this->assertSame('xn--rvztr-wqa0gx3bwi.hu', DomainCanonicalizer::normalize('Árvíztűrő.hu'));
    }

    #[RequiresPhpExtension('intl')]
    public function test_domain_idn_conversion_failure_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DomainCanonicalizer::normalize("a\u{0378}b.hu");
    }

    /** @return array<string, array{string}> */
    public static function invalidDomains(): array
    {
        return array_map(fn (string $v): array => [$v], [
            'empty' => '',
            'no dot' => 'foo',
            'scheme only' => 'http://',
            'ipv4' => '192.168.0.1',
            'ipv6' => '[::1]',
            'words' => 'nem egy domain',
        ]);
    }

    #[DataProvider('invalidDomains')]
    public function test_domain_invalid_throws_and_canonicalize_returns_null(string $input): void
    {
        try {
            DomainCanonicalizer::normalize($input);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->assertNull((new DomainCanonicalizer)->canonicalize($input));
        }
    }

    public function test_registry_resolves_by_type_and_name(): void
    {
        $registry = app(CanonicalizerRegistry::class);

        $this->assertSame($registry, app(CanonicalizerRegistry::class));
        $this->assertInstanceOf(EmailCanonicalizer::class, $registry->for(FieldType::Email));
        $this->assertInstanceOf(PhoneCanonicalizer::class, $registry->for(FieldType::Phone));
        $this->assertInstanceOf(DomainCanonicalizer::class, $registry->for(FieldType::Domain));
        $this->assertInstanceOf(TextCanonicalizer::class, $registry->for(FieldType::Integer));
        $this->assertInstanceOf(TaxNumberCanonicalizer::class, $registry->for('tax_number'));
        $this->assertInstanceOf(EmailCanonicalizer::class, $registry->for('email'));
    }

    public function test_registry_extend_and_unknown_name(): void
    {
        $registry = new CanonicalizerRegistry;
        $custom = new TextCanonicalizer;
        $registry->extend('custom', $custom);

        $this->assertSame($custom, $registry->for('custom'));

        $this->expectException(InvalidArgumentException::class);
        $registry->for('missing');
    }

    public function test_email_prefilter_on_sqlite(): void
    {
        $this->seedPartners('email', ['  Foo@Example.test ', 'bar@example.test', null, 'other@example.test']);

        $this->assertSame(
            ['  Foo@Example.test ', 'bar@example.test'],
            $this->prefiltered(new EmailCanonicalizer, 'email', ['foo@example.test', 'bar@example.test']),
        );
    }

    public function test_phone_prefilter_on_sqlite(): void
    {
        $this->seedPartners('phone', ['+36 (30) 111-2222', '36/30.111 2222', '06301112222', null]);

        $this->assertSame(
            ['+36 (30) 111-2222', '36/30.111 2222'],
            $this->prefiltered(new PhoneCanonicalizer, 'phone', ['36301112222']),
        );
    }

    public function test_tax_number_prefilter_on_sqlite(): void
    {
        $this->seedPartners('tax_number', ['12345678-2-41', 'DE 123.456/789', '99999999-1-11']);

        $this->assertSame(
            ['12345678-2-41', 'DE 123.456/789'],
            $this->prefiltered(new TaxNumberCanonicalizer, 'tax_number', ['12345678241', 'de123456789']),
        );
    }

    public function test_domain_prefilter_on_sqlite_includes_www_variant(): void
    {
        $this->seedPartners('domain', ['pelda.hu', 'WWW.Pelda.hu', 'other.hu', 'sub.pelda.hu']);

        $this->assertSame(
            ['pelda.hu', 'WWW.Pelda.hu'],
            $this->prefiltered(new DomainCanonicalizer, 'domain', ['pelda.hu']),
        );
    }

    public function test_text_prefilter_on_sqlite(): void
    {
        $this->seedPartners('city', ['  Győr ', 'győr', 'Gyor', 'Pécs']);

        $this->assertSame(
            ['  Győr ', 'győr'],
            $this->prefiltered(new TextCanonicalizer, 'city', ['  GYŐR']),
        );
    }

    public function test_prefilter_with_no_keys_matches_nothing(): void
    {
        $this->seedPartners('email', ['a@example.test']);

        $this->assertSame([], $this->prefiltered(new EmailCanonicalizer, 'email', []));
    }

    public function test_prefilter_accepts_query_builder(): void
    {
        $this->seedPartners('email', ['A@example.test', 'b@example.test']);

        $query = DB::table('partners')->orderBy('id');
        $this->assertInstanceOf(Builder::class, $query);
        (new EmailCanonicalizer)->prefilter($query, 'email', ['a@example.test']);

        $this->assertSame(['A@example.test'], $query->pluck('email')->all());
    }

    /**
     * @param  list<string|null>  $values
     */
    private function seedPartners(string $column, array $values): void
    {
        foreach ($values as $i => $value) {
            Partner::create(['name' => 'P'.$i, $column => $value]);
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function prefiltered(Canonicalizer $canonicalizer, string $column, array $keys): array
    {
        $query = Partner::query()->orderBy('id');
        $canonicalizer->prefilter($query, $column, $keys);

        /** @var list<string> $values */
        $values = $query->pluck($column)->all();

        return $values;
    }
}
