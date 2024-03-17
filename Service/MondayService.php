<?php

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MondayService
{
    function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly GraphqlClient         $client)
    {
    }

    public function getItemsByBoardId(int $boardId): array
    {
        $token = $this->parameterBag->get('app.monday.token');

        $query = <<< 'GRAPHQL'
                        query ($ids: [ID!], $cursor: String) {
                            complexity {
                                before
                                query
                                after
                                reset_in_x_seconds
                            }
                            boards(ids: $ids) {
                                 name
                                 items_count
                                items_page(limit: 100, cursor: $cursor) {
                                  cursor
                                  items {
                                    id
                                    name
                                    column_values {
                                      column {
                                        id
                                        title
                                        type
                                      }
                                      ... on BoardRelationValue {
                                        id
                                        display_value
                                        }
                                      ... on StatusValue {
                                        index
                                        value
                                        
                                        }
                                      id
                                      text
                                      value
                                    }
                                    subitems {
                                      id
                                      name
                                      column_values {
                                        id
                                        column {
                                          id
                                          title
                                          type
                                        }
                                        ... on FileValue {
                                            files
                                            id
                                          }
                                        ... on TagsValue {
                                            tag_ids
                                            text
                                          }
                                        ... on StatusValue {
                                            index
                                            text
                                            value
                                          }
                                        value
                                        text
                                      }
                                    }
                                  }
                                }
                              }
                            }
        GRAPHQL;

        $items = [];
        $cursor = null;
        do {
            $result = $this->client->query('https://api.monday.com/v2',
                $query, ['ids' => $boardId,
                    'cursor' => $cursor],
                $token
            );
            $board = reset($result['boards']);
            $cursor = $board['items_page']['cursor'];
            $items = array_merge($items, $board['items_page']['items']);

        } while ($cursor);

        return $items;
    }
}
