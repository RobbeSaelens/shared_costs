<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the Stal Manager API recipe: the contract that apps rely on.
 *
 * Over HTTP, as an app does it: logged in with Drupal's session cookie, with
 * the CSRF token in a header on every request that changes something.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StalManagerApiRecipeTest extends GenericRecipeTestBase {

  use StalManagerTestTrait;

  /**
   * The app's cookies: its session, once it has logged in.
   */
  private CookieJar $cookies;

  /**
   * The CSRF token, sent with every request that changes something.
   */
  private ?string $token = NULL;

  /**
   * {@inheritdoc}
   */
  protected function getRecipePath(): string {
    return $this->recipe('stal_manager_api');
  }

  /**
   * Tests logging in and out, and reading and writing the stable's content.
   */
  public function testApi(): void {
    $this->doApply();
    $luna = $this->createHorse('Luna');
    $bella = $this->createHorse('Bella', 'livery');
    // A horse that left the stable.
    $this->createHorse('Storm')->setUnpublished()->save();
    $hay = $this->createItem('Hay', 'feed', '8.00', TRUE);
    $this->createItem('Straw', 'bedding', '6.50', FALSE);
    $this->createExpense('2026-10-31', 'Straw', 'bedding', '13.00', [[$luna], [$bella]]);
    $vet = $this->createExpense('2026-11-01', 'Vet check-up', 'vet', '12.35', [[$luna], [$bella]]);
    $directory = 'private://receipts';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $receipt = $this->container->get('file.repository')->writeData('Vet: 12.35', "$directory/vet.txt", FileExists::Error);
    $vet->set('field_receipt', $receipt)->save();
    $this->createExpense('2026-11-15', 'Hay', 'feed', '80.00', [[$luna, '75'], [$bella]])
      ->set('field_inventory_item', $hay)
      ->set('field_quantity', '10')
      ->set('field_unit_price', '8.00')
      ->save();
    $this->createExpense('2026-11-30', 'To the show', 'transport', '45.00', [[$luna]]);
    $this->createExpense('2026-12-01', 'Farrier', 'farrier', '90.00', [[$luna], [$bella]]);
    $manager = $this->createStableManager();
    $password = $this->randomMachineName(16);
    $manager->setPassword($password)->save();

    // Visitors: no costs, no receipts, no changes.
    $this->cookies = new CookieJar();
    $this->assertVisitorSeesNothing($vet, $bella);
    $users = $this->get('jsonapi/user/user');
    $this->assertNotEmpty($users['data']);
    foreach ($users['data'] as $user) {
      // Core shows user names to everyone, and nothing else.
      $this->assertSame(['display_name'], array_keys($user['attributes']));
      $this->assertArrayNotHasKey('relationships', $user);
    }

    // A wrong password: 400.
    $response = $this->logIn($manager->getAccountName(), 'wrong');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(['message' => 'Unrecognized username or password.'], $this->json($response));

    // Logging in: a session cookie, and the tokens to change content and to
    // log out.
    $response = $this->logIn($manager->getAccountName(), $password);
    $this->assertSame(200, $response->getStatusCode());
    $login = $this->json($response);
    $this->assertSame(['uid' => (string) $manager->id(), 'name' => $manager->getAccountName()], $login['current_user']);
    $this->assertIsString($login['csrf_token']);
    $this->assertIsString($login['logout_token']);
    $sessions = array_filter($this->cookies->toArray(), static fn (array $cookie): bool => (bool) preg_match('/^S?SESS/', $cookie['Name']));
    $this->assertCount(1, $sessions);
    $this->assertTrue(reset($sessions)['HttpOnly']);
    $this->assertSame('1', $this->loginStatus());
    // Logged in already: log out first.
    $this->assertSame(403, $this->logIn($manager->getAccountName(), $password)->getStatusCode());
    // The CSRF token can also be fetched again (after a reload of the app).
    $response = $this->request('GET', 'session/token');
    $this->assertSame(200, $response->getStatusCode());
    $session_token = (string) $response->getBody();
    $this->assertNotSame('', $session_token);

    // The horses in the stable.
    $horses = $this->get('jsonapi/node/horse', ['sort' => 'title']);
    $this->assertSame(['Bella', 'Luna'], $this->titles($horses));
    $this->assertSame($luna->uuid(), $horses['data'][1]['id']);
    $this->assertSame(['status' => TRUE, 'title' => 'Luna', 'field_horse_type' => 'own'], $this->attributes($horses['data'][1], [
      'status',
      'title',
      'field_horse_type',
    ]));
    // The inventory, and its quick adds.
    $items = $this->get('jsonapi/node/inventory_item', ['sort' => 'title']);
    $this->assertSame(['Hay', 'Straw'], $this->titles($items));
    $this->assertSame([
      'title' => 'Hay',
      'field_cost_category' => 'feed',
      'field_default_price' => '8.00',
      'field_quick_add' => TRUE,
      'field_unit' => 'bale',
    ], $this->attributes($items['data'][0], [
      'title',
      'field_cost_category',
      'field_default_price',
      'field_quick_add',
      'field_unit',
    ]));
    $this->assertFalse($items['data'][1]['attributes']['field_quick_add']);
    $this->assertSame(['Hay'], $this->titles($this->get('jsonapi/node/inventory_item', ['filter' => ['field_quick_add' => '1']])));

    // A month of expenses, the newest first, with their horses and items.
    $november = $this->get('jsonapi/node/expense', [
      'filter' => [
        'from' => ['condition' => ['path' => 'field_expense_date', 'operator' => '>=', 'value' => '2026-11-01']],
        'to' => ['condition' => ['path' => 'field_expense_date', 'operator' => '<', 'value' => '2026-12-01']],
      ],
      'sort' => '-field_expense_date',
      'include' => 'field_shared_with,field_inventory_item',
    ]);
    $this->assertSame(['To the show', 'Hay', 'Vet check-up'], $this->titles($november));
    [$show, $hay_expense, $vet_expense] = $november['data'];
    $this->assertSame('transport', $show['attributes']['field_cost_category']);
    $this->assertSame([
      'title' => 'Hay',
      'field_cost_category' => 'feed',
      'field_expense_date' => '2026-11-15',
      'field_quantity' => '10.00',
      'field_total' => '80.00',
      'field_unit_price' => '8.00',
    ], $this->attributes($hay_expense, [
      'title',
      'field_cost_category',
      'field_expense_date',
      'field_quantity',
      'field_total',
      'field_unit_price',
    ]));
    // Each horse's share (NULL: an equal part of the rest) and amount, in
    // the meta of its resource identifier.
    $this->assertSame([
      [
        'type' => 'node--horse',
        'id' => $luna->uuid(),
        'meta' => ['share' => '75.00', 'amount' => '60.00', 'drupal_internal__target_id' => (int) $luna->id()],
      ],
      [
        'type' => 'node--horse',
        'id' => $bella->uuid(),
        'meta' => ['share' => NULL, 'amount' => '20.00', 'drupal_internal__target_id' => (int) $bella->id()],
      ],
    ], $hay_expense['relationships']['field_shared_with']['data']);
    $this->assertSame([
      'type' => 'node--inventory_item',
      'id' => $hay->uuid(),
      'meta' => ['drupal_internal__target_id' => (int) $hay->id()],
    ], $hay_expense['relationships']['field_inventory_item']['data']);
    $this->assertSame(['6.18', '6.17'], $this->sharers($vet_expense, 'amount'));
    $this->assertNull($vet_expense['relationships']['field_inventory_item']['data']);
    $included = array_map(static fn (array $resource): string => $resource['type'] . ':' . $resource['attributes']['title'], $november['included']);
    sort($included);
    $this->assertSame(['node--horse:Bella', 'node--horse:Luna', 'node--inventory_item:Hay'], $included);
    // The receipt, for those who can see the expense.
    $with_receipt = $this->get('jsonapi/node/expense/' . $vet->uuid(), ['include' => 'field_receipt']);
    $uri = $with_receipt['included'][0]['attributes']['uri'];
    $this->assertSame('private://receipts/vet.txt', $uri['value']);
    $response = $this->request('GET', $uri['url']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Vet: 12.35', (string) $response->getBody());

    // Adding an expense with an id made by the app, so that it can send it
    // again when it doesn't know whether it arrived (offline, or a timeout).
    $this->token = $login['csrf_token'];
    $id = $this->container->get('uuid')->generate();
    $document = $this->expense($id, [
      'title' => 'Hay',
      'field_expense_date' => '2026-11-20',
      'field_cost_category' => 'feed',
      'field_total' => '12.35',
      'field_quantity' => '1',
      'field_unit_price' => '12.35',
    ], [$luna->uuid() => NULL, $bella->uuid() => NULL], $hay->uuid());
    $response = $this->api('POST', 'jsonapi/node/expense', $document);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    $created = $this->json($response)['data'];
    $this->assertSame($id, $created['id']);
    $this->assertStringContainsString("/jsonapi/node/expense/$id", $response->getHeaderLine('Location'));
    $this->assertSame(['6.18', '6.17'], $this->sharers($created, 'amount'));
    $this->assertSame([NULL, NULL], $this->sharers($created, 'share'));
    $this->assertSame($hay->uuid(), $created['relationships']['field_inventory_item']['data']['id']);
    $this->assertSame('12.35', $created['attributes']['field_total']);
    // Sent again: 409 Conflict, and still one expense.
    $count = $this->countExpenses();
    $response = $this->api('POST', 'jsonapi/node/expense', $document);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('Conflict: Entity already exists.', $this->json($response)['errors'][0]['detail']);
    $this->assertSame($count, $this->countExpenses());
    $this->assertSame(1, $this->countExpenses($id));
    // For one horse, the meta can be left out.
    $response = $this->api('POST', 'jsonapi/node/expense', $this->expense($this->container->get('uuid')->generate(), [
      'title' => 'Lesson',
      'field_expense_date' => '2026-11-21',
      'field_cost_category' => 'lesson',
      'field_total' => '30.00',
    ], [$luna->uuid() => FALSE]));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(['30.00'], $this->sharers($this->json($response)['data'], 'amount'));
    // Without the CSRF token, nothing changes.
    $this->token = NULL;
    $response = $this->api('POST', 'jsonapi/node/expense', $this->expense($this->container->get('uuid')->generate(), [
      'title' => 'No token',
    ], [$luna->uuid() => NULL]));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('X-CSRF-Token request header is missing', $this->json($response)['errors'][0]['detail']);

    // Changing an expense: the amounts follow the total, and the shares.
    $this->token = $session_token;
    $response = $this->api('PATCH', "jsonapi/node/expense/$id", $this->expense($id, ['field_total' => '20.01']));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(['10.01', '10.00'], $this->sharers($this->json($response)['data'], 'amount'));
    $response = $this->api('PATCH', "jsonapi/node/expense/$id", $this->expense($id, [], [
      $luna->uuid() => '60',
      $bella->uuid() => NULL,
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(['12.01', '8.00'], $this->sharers($this->json($response)['data'], 'amount'));
    // As sent; read again, as stored.
    $this->assertSame(['60', NULL], $this->sharers($this->json($response)['data'], 'share'));
    $changed = $this->get("jsonapi/node/expense/$id")['data'];
    $this->assertSame(['60.00', NULL], $this->sharers($changed, 'share'));
    $this->assertSame('20.01', $changed['attributes']['field_total']);
    // Shares that add up to more than 100 % are refused.
    $response = $this->api('PATCH', "jsonapi/node/expense/$id", $this->expense($id, [], [
      $luna->uuid() => '60',
      $bella->uuid() => '50',
    ]));
    $this->assertSame(422, $response->getStatusCode());

    // Deleting it.
    $this->assertSame(204, $this->api('DELETE', "jsonapi/node/expense/$id")->getStatusCode());
    $this->assertSame(404, $this->api('GET', "jsonapi/node/expense/$id")->getStatusCode());
    $this->assertSame(0, $this->countExpenses($id));

    // Inventory items: added, changed and deleted, with their quick add.
    $carrots = $this->container->get('uuid')->generate();
    $response = $this->api('POST', 'jsonapi/node/inventory_item', $this->resource('inventory_item', $carrots, [
      'title' => 'Carrots',
      'field_cost_category' => 'feed',
      'field_default_price' => '4.50',
      'field_unit' => 'bag',
      'field_quick_add' => TRUE,
    ]));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    $this->assertTrue($this->json($response)['data']['attributes']['field_quick_add']);
    $quick_adds = ['filter' => ['field_quick_add' => '1'], 'sort' => 'title'];
    $this->assertSame(['Carrots', 'Hay'], $this->titles($this->get('jsonapi/node/inventory_item', $quick_adds)));
    $response = $this->api('PATCH', "jsonapi/node/inventory_item/$carrots", $this->resource('inventory_item', $carrots, [
      'field_quick_add' => FALSE,
      'field_default_price' => '5.00',
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertFalse($this->json($response)['data']['attributes']['field_quick_add']);
    $this->assertSame(['Hay'], $this->titles($this->get('jsonapi/node/inventory_item', $quick_adds)));
    $this->assertSame(204, $this->api('DELETE', "jsonapi/node/inventory_item/$carrots")->getStatusCode());

    // Horses: changed, and unpublished when they leave the stable. They are
    // never deleted: their part of past costs stays.
    $bella_id = (string) $bella->uuid();
    $response = $this->api('PATCH', "jsonapi/node/horse/$bella_id", $this->resource('horse', $bella_id, ['field_horse_type' => 'own']));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame('own', $this->json($response)['data']['attributes']['field_horse_type']);
    $luna_id = (string) $luna->uuid();
    $response = $this->api('PATCH', "jsonapi/node/horse/$luna_id", $this->resource('horse', $luna_id, ['status' => FALSE]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(['Bella'], $this->titles($this->get('jsonapi/node/horse')));
    $this->assertSame(403, $this->api('DELETE', "jsonapi/node/horse/$luna_id")->getStatusCode());
    $vet_expense = $this->get('jsonapi/node/expense/' . $vet->uuid())['data'];
    $this->assertSame([$luna_id, $bella_id], array_column($vet_expense['relationships']['field_shared_with']['data'], 'id'));
    $this->assertSame(['6.18', '6.17'], $this->sharers($vet_expense, 'amount'));

    // Logging out ends the session.
    $response = $this->request('POST', 'user/logout', [
      RequestOptions::QUERY => ['_format' => 'json', 'token' => $login['logout_token']],
    ]);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertVisitorSeesNothing($vet, $bella);
    $this->assertSame(403, $this->request('GET', $uri['url'])->getStatusCode());
  }

  /**
   * Asserts that someone without a session can't see or change any costs.
   */
  private function assertVisitorSeesNothing(NodeInterface $expense, NodeInterface $horse): void {
    $this->assertSame('0', $this->loginStatus());
    // Lists are empty: JSON:API only names what it left out (in meta.omitted).
    foreach (['node/expense', 'node/horse', 'node/inventory_item', 'file/file'] as $type) {
      $this->assertSame([], $this->get("jsonapi/$type")['data'], "No $type.");
    }
    $path = 'jsonapi/node/expense/' . $expense->uuid();
    $this->assertSame(403, $this->api('GET', $path)->getStatusCode());
    $new = $this->expense($this->container->get('uuid')->generate(), ['title' => 'Visitor'], [(string) $horse->uuid() => NULL]);
    $this->assertSame(403, $this->api('POST', 'jsonapi/node/expense', $new)->getStatusCode());
    $this->assertSame(403, $this->api('PATCH', $path, $this->expense((string) $expense->uuid(), ['field_total' => '1.00']))->getStatusCode());
    $this->assertSame(403, $this->api('DELETE', $path)->getStatusCode());
  }

  /**
   * Creates an inventory item.
   */
  private function createItem(string $title, string $category, string $price, bool $quick_add): NodeInterface {
    $item = Node::create([
      'type' => 'inventory_item',
      'title' => $title,
      'field_cost_category' => $category,
      'field_default_price' => $price,
      'field_unit' => 'bale',
      'field_quick_add' => $quick_add,
    ]);
    $item->save();
    return $item;
  }

  /**
   * Builds an expense's JSON:API document, as the app sends it.
   *
   * @param string $id
   *   The expense's UUID.
   * @param array<string, string> $attributes
   *   The attributes to send.
   * @param array<string, string|false|null> $horses
   *   The horses that share it, by UUID: each with its share, NULL for an
   *   equal part of the rest, or FALSE to send no meta at all.
   * @param string|null $item
   *   The inventory item's UUID, if any.
   *
   * @return array{data: array<string, mixed>}
   *   The document.
   */
  private function expense(string $id, array $attributes, array $horses = [], ?string $item = NULL): array {
    $document = $this->resource('expense', $id, $attributes);
    if ($horses) {
      $document['data']['relationships']['field_shared_with']['data'] = array_map(
        static fn (string $uuid, string|false|null $share): array => ['type' => 'node--horse', 'id' => $uuid]
          + ($share === FALSE ? [] : ['meta' => ['share' => $share]]),
        array_keys($horses),
        $horses,
      );
    }
    if ($item !== NULL) {
      $document['data']['relationships']['field_inventory_item']['data'] = [
        'type' => 'node--inventory_item',
        'id' => $item,
      ];
    }
    return $document;
  }

  /**
   * Builds a node's JSON:API document.
   *
   * @param string $bundle
   *   The content type.
   * @param string $id
   *   The node's UUID.
   * @param array<string, mixed> $attributes
   *   The attributes to send.
   *
   * @return array{data: array<string, mixed>}
   *   The document.
   */
  private function resource(string $bundle, string $id, array $attributes): array {
    $data = ['type' => "node--$bundle", 'id' => $id];
    if ($attributes) {
      $data['attributes'] = $attributes;
    }
    return ['data' => $data];
  }

  /**
   * Returns some of a resource object's attributes.
   *
   * @param array<string, mixed> $resource
   *   The resource object.
   * @param list<string> $names
   *   The attributes, in the order of the resource object.
   *
   * @return array<string, mixed>
   *   The attributes' values, keyed by name.
   */
  private function attributes(array $resource, array $names): array {
    return array_intersect_key($resource['attributes'], array_flip($names));
  }

  /**
   * Returns a meta value of each horse that shares an expense, in order.
   *
   * @param array<string, mixed> $expense
   *   The expense's resource object.
   * @param string $key
   *   The meta's key: share or amount.
   *
   * @return list<string|null>
   *   The values.
   */
  private function sharers(array $expense, string $key): array {
    return array_column(array_column($expense['relationships']['field_shared_with']['data'], 'meta'), $key);
  }

  /**
   * Returns the titles of the resource objects in a document.
   *
   * @param array<string, mixed> $document
   *   The document.
   *
   * @return list<string>
   *   The titles.
   */
  private function titles(array $document): array {
    return array_column(array_column($document['data'], 'attributes'), 'title');
  }

  /**
   * Counts the expenses in the database, or those with a UUID.
   */
  private function countExpenses(?string $uuid = NULL): int {
    $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'expense');
    if ($uuid !== NULL) {
      $query->condition('uuid', $uuid);
    }
    return (int) $query->count()->execute();
  }

  /**
   * Logs in, as the app does.
   */
  private function logIn(string $name, string $password): ResponseInterface {
    return $this->request('POST', 'user/login', [
      RequestOptions::QUERY => ['_format' => 'json'],
      RequestOptions::JSON => ['name' => $name, 'pass' => $password],
    ]);
  }

  /**
   * Returns whether the app is logged in: '1' or '0'.
   */
  private function loginStatus(): string {
    $response = $this->request('GET', 'user/login_status', [RequestOptions::QUERY => ['_format' => 'json']]);
    return (string) $response->getBody();
  }

  /**
   * Gets a JSON:API document, which must be there.
   *
   * @param string $path
   *   The path, such as jsonapi/node/expense.
   * @param array<string, mixed> $query
   *   The query parameters.
   *
   * @return array<string, mixed>
   *   The document.
   */
  private function get(string $path, array $query = []): array {
    $response = $this->request('GET', $path, [
      RequestOptions::QUERY => $query,
      RequestOptions::HEADERS => ['Accept' => 'application/vnd.api+json'],
    ]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    return $this->json($response);
  }

  /**
   * Sends a JSON:API request, with the CSRF token when it changes something.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path, such as jsonapi/node/expense.
   * @param array<string, mixed>|null $document
   *   The document to send, if any.
   */
  private function api(string $method, string $path, ?array $document = NULL): ResponseInterface {
    $headers = ['Accept' => 'application/vnd.api+json'];
    $options = [];
    if ($document !== NULL) {
      $headers['Content-Type'] = 'application/vnd.api+json';
      $options[RequestOptions::BODY] = json_encode($document, JSON_THROW_ON_ERROR);
    }
    if ($this->token !== NULL && $method !== 'GET') {
      $headers['X-CSRF-Token'] = $this->token;
    }
    return $this->request($method, $path, $options + [RequestOptions::HEADERS => $headers]);
  }

  /**
   * Sends a request to the test site, with the app's cookies.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path.
   * @param array<string, mixed> $options
   *   Guzzle's request options.
   */
  private function request(string $method, string $path, array $options = []): ResponseInterface {
    return $this->getHttpClient()->request($method, $this->buildUrl($path), $options + [
      RequestOptions::COOKIES => $this->cookies,
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  private function json(ResponseInterface $response): array {
    $data = json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertIsArray($data);
    return $data;
  }

}
