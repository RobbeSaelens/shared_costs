<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that the shares of a cost add up, and that nobody is listed twice.
 */
#[Constraint(
  id: 'SharedCostsAllocationShares',
  label: new TranslatableMarkup('Shares of shared costs', [], ['context' => 'Validation']),
)]
final class AllocationSharesConstraint extends SymfonyConstraint {

  /**
   * Builds the constraint.
   *
   * @param string $tooMuchMessage
   *   When the given shares add up to more than 100 %.
   * @param string $notWholeMessage
   *   When every sharer has a share, but they don't add up to 100 %.
   * @param string $duplicateMessage
   *   When a sharer is listed more than once.
   * @param string[]|null $groups
   *   The validation groups.
   * @param mixed $payload
   *   Domain-specific data.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $tooMuchMessage = 'The shares add up to @sum %, which is more than 100 %.',
    public string $notWholeMessage = 'The shares add up to @sum %. Make them add up to 100 %, or leave a share empty to give that sharer the rest.',
    public string $duplicateMessage = '%label is listed more than once.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    // Named arguments: Symfony 8 (Drupal 12) drops the $options parameter.
    parent::__construct(groups: $groups, payload: $payload);
  }

}
