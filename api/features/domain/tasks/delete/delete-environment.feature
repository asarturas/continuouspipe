Feature:
  In order to clean-up resources after I used them
  As a DevOps engineer
  I want my pipeline to delete an environment after it has been created

  Scenario: It deletes an environment after creating it
    Given I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        infrastructure:
            deploy:
                cluster: foo
                environment:
                    name: '"app-" ~ code_reference.branch'
                services:
                    foo:
                        specification:
                            source:
                                image: foo

        cleanup:
            delete:
                cluster: foo
                environment:
                    name: '"app-" ~ code_reference.branch'
    """
    When a tide is started for the branch "master"
    And the deployment succeed
    Then the environment "app-master" should have been deleted from the cluster "foo"

  Scenario: It deletes default environments
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        infrastructure:
            deploy:
                cluster: foo
                services:
                    foo:
                        specification:
                            source:
                                image: foo

        cleanup:
            delete:
                cluster: foo
    """
    When a tide is started for the branch "master"
    And the deployment succeed
    Then the environment "00000000-0000-0000-0000-000000000000-master" should have been deleted from the cluster "foo"

  Scenario: It deletes the right environment with defaults
    Given I have a "continuous-pipe.yml" file in my repository that contains:
    """
    defaults:
        cluster: foo
        environment:
            name: '"app-" ~ code_reference.branch'
    tasks:
        infrastructure:
            deploy:
                services:
                    foo:
                        specification:
                            source:
                                image: foo

        cleanup:
            delete: ~
    """
    When a tide is started for the branch "master"
    And the deployment succeed
    Then the environment "app-master" should have been deleted from the cluster "foo"
