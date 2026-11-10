<?php
/**
 * One email provider, read-only: enough of its API to carry an account's
 * suppressions, contacts and templates across.
 *
 * Every method hands data back in this module's shape, whatever the provider
 * calls things:
 *
 *   suppressions  ['email' => , 'reason' => unsubscribe|bounce|complaint, 'detail' => ]
 *   contacts      ['email' => , 'first_name' => , 'last_name' => ,
 *                  'fields' => [name => value], 'tags' => [..],
 *                  'status' => subscribed|unsubscribed|bounced|complained|pending]
 *   templates     ['name' => , 'subject' => , 'html' => ]  merge tags still in
 *                 the provider's syntax; the importer runs convertMergeTags()
 *
 * Nothing here writes to the provider, and nothing here writes to the store:
 * the import runner does that with what fetch() returns.
 */
interface YSRTech_EmailCampaigns_Model_Import_Provider_Interface
{
    public const STAGE_SUPPRESSIONS = 'suppressions';
    public const STAGE_CONTACTS     = 'contacts';
    public const STAGE_TEMPLATES    = 'templates';

    /**
     * @param array $credentials ['api_key' => ..., plus whatever getCredentialFields() asks for]
     * @return $this
     */
    public function setCredentials(array $credentials);

    /** The provider's name as people write it, e.g. "Mailchimp" */
    public function getName(): string;

    /**
     * What to ask the admin for besides the API key.
     *
     * @return array<string, array{label:string, note?:string, required?:bool}> field code => description
     */
    public function getCredentialFields(): array;

    /** Where in the provider's own UI a read-only key is made, as one sentence */
    public function getKeyHelp(): string;

    /**
     * The account the key belongs to. The cheapest call that proves the key.
     *
     * @return array{name:string}
     * @throws YSRTech_EmailCampaigns_Model_Import_Provider_Exception
     */
    public function account(): array;

    /**
     * What there is to bring across. Null for a count the provider will not
     * give without reading everything.
     *
     * @return array{contacts:int|null, suppressions:int|null, templates:int|null, notes:string[]}
     */
    public function discover(): array;

    /**
     * The stages this provider supports, in the order they must run.
     * Suppressions first, always: contacts imported before them could be
     * mailed in between.
     *
     * @return string[]
     */
    public function stages(): array;

    /**
     * One page of one stage.
     *
     * The cursor is opaque to the caller: whatever this returned last time,
     * JSON-encoded into the import row and handed back. Null starts the
     * stage; a null cursor in the result ends it.
     *
     * @param  array|null $cursor
     * @return array{items:array<int, array>, cursor:array|null, total?:int|null}
     * @throws YSRTech_EmailCampaigns_Model_Import_Provider_Exception
     */
    public function fetch(string $stage, ?array $cursor, array $options = []): array;

    /**
     * Rewrite the provider's merge tags as this module's:
     * {{customer.firstname|there}}, {{customer.lastname}},
     * {{customer.email}}, {{store.name}}, {{unsubscribe_url}}.
     * A tag with no equivalent is left exactly as it was, so it is visible
     * in the template rather than silently gone.
     */
    public function convertMergeTags(string $html): string;
}
