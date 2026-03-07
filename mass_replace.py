import os

files_to_update = [
    "/home/ubuntu/stacks/synapse-bundle/packages/chat/src/Controller/Api/ChatApiController.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/tests/Unit/Controller/DashboardControllerTest.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/src/Twig/SynapseTwigExtension.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/src/Controller/DashboardController.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/src/Controller/Intelligence/ProviderController.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/src/Controller/Intelligence/ConfigurationLlmController.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/src/Controller/Intelligence/MissionController.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/admin/src/Controller/Usage/QuotasController.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/README.md",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/docs/reference/entities.md",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/tests/Unit/Agent/SynapseAgentTest.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/tests/Unit/Agent/PresetValidatorAgentTest.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/Agent/SynapseAgentBuilder.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/Agent/SynapseAgent.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/Agent/README.md",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/Agent/PresetValidator/PresetValidatorAgent.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/DataFixtures/SynapsePresetFixture.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/MessageHandler/TestPresetMessageHandler.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/Engine/ChatService.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/src/PresetValidator.php",
    "/home/ubuntu/stacks/synapse-bundle/packages/core/config/core.yaml"
]

for f in files_to_update:
    if os.path.exists(f):
        with open(f, 'r') as file:
            content = file.read()
            
        content = content.replace('SynapsePresetRepository', 'SynapseModelPresetRepository')
        content = content.replace('SynapsePreset', 'SynapseModelPreset')
        content = content.replace('synapse_preset', 'synapse_model_preset')
        
        with open(f, 'w') as file:
            file.write(content)

# We should also rename the file 
# /home/ubuntu/stacks/synapse-bundle/packages/admin/src/Controller/Intelligence/PresetController.php
# to ModelPresetController.php and SynapsePresetFixture.php
