Feature:
  In order to setup continuous delivery on a new project
  As a developer
  I need to be able to create a new flow

  Background:
    Given I am authenticated as "samuel"

  Scenario: I can list the flows of a team
    Given the team "samuel" exists
    And I have a flow with UUID "00000000-0000-0000-0000-000000000000" in the team "samuel"
    When I retrieve the list of the flows of the team "samuel"
    Then I should see the flow "00000000-0000-0000-0000-000000000000"

  Scenario: I can see the last tide of the flow
    Given the team "samuel" exists
    And I have a flow in the team "samuel"
    And a tide is created
    When I retrieve the list of the flows of the team "samuel"
    Then I should see the flow's last tide

  @smoke
  Scenario: I can update the configuration of a flow
    Given the team "samuel" exists
    And I am authenticated as "Alice"
    And the user "Alice" is "ADMIN" of the team "samuel"
    And I have a flow in the team "samuel"
    When I send an update request with a configuration
    Then the flow is successfully saved

  Scenario: Only administrators can update a flow
    Given the team "samuel" exists
    And I am authenticated as "Bob"
    And the user "Bob" is "USER" of the team "samuel"
    And I have a flow in the team "samuel"
    When I send an update request with a configuration
    Then the flow is not saved because of an authorization exception

  Scenario: It understands an invalid YAML
    Given the team "samuel" exists
    And I am authenticated as "Alice"
    And the user "Alice" is "ADMIN" of the team "samuel"
    And I have a flow in the team "samuel"
    When I send an update request with the following configuration:
    """
    []
    filter: 'code_reference.branch == "feature/continuous-pipe-migration"'
    """
    Then the flow is not saved because of a bad request error

  @smoke
  Scenario: It allows to delete a flow that have tides that have pipelines
    Given the team "samuel" exists
    And the user "samuel" is "ADMIN" of the team "samuel"
    And I have a flow with UUID "00000000-0000-0000-0000-111122223333" in the team "samuel"
    Given I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        images:
            build: ~

    pipelines:
        - name: To master
          tasks: [ images ]
    """
    And a tide is created
    When I delete the flow "00000000-0000-0000-0000-111122223333"
    Then the flow should be successfully deleted

  Scenario: The list looks good even with non flexified flows
    Given the team "samuel" exists
    And I have a flow with UUID "00000000-0000-0000-0000-000000000000" in the team "samuel"
    And I have a flow with UUID "11111111-0000-0000-0000-000000000000" in the team "samuel"
    And the flow "11111111-0000-0000-0000-000000000000" has flex activated
    When I retrieve the list of the flows of the team "samuel"
    Then I should see the flow "00000000-0000-0000-0000-000000000000"
    And I should see the flow "11111111-0000-0000-0000-000000000000"
