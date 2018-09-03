<template>
    <transition name="modal">
        <div class="modal-mask" v-if="show">
            <div class="modal-wrapper">
                <div class="modal-container">

                    <div class="modalheader">
                        <div>Whats and Hows</div>
                        <div class="close-modal" @click="close">X</div>
                    </div>

                    <div class="modalbody">
                        <div class="info-section">
                            <ol>
                                <li>Post art as usual</li>
                                <li>Gain territory automatically</li>
                                <li>???</li>
                                <li><b>Profit</b></li>
                            </ol>
                        </div>

                        <div class="info-section">
                            The map updates every 30 minutes. If you can't wait that long, click <a target="_blank" href="/import">here</a> to trigger an update.
                        </div>

                        <div class="info-section">
                            Your army only has enough strength to expand to a maximum of <b>3</b> new territories a day.
                        </div>

                        <div class="info-section">
                            For more control, go to your user page and hit the checkbox labeled <b>"Enable External API"</b>.
                            This will give you an input field for commands when you submit your images.
                            All commands are cAsE-iNsEnSiTiVe and given in the format of <b>COMMAND:VALUE</b>. Multiple commands can be sent at once with a <b>,</b> separating them i.e <b>MOVE:NORTH,COLOR:#23E4DF,UPDATE_AVATAR</b>

                            <br /><br />If your commands seem to fail, check the command log for more info <a target="_blank" href="/log">here</a>.
                        </div>

                        <div class="info-section">
                            <p><b><u>Commands available:</u></b></p>
                            <p><b>START:ID</b></p> - Change ID to actual territory ID. Set your starting position for your first territory. Only works on unoccupied territories.
                            You start in a random unoccupied area if not given (or completely random if the map is full).
                            <p><b>COLOR:#000000</b></p> - Change #000000 to desired color. Changes your map and name color. Colors have to be unique across all users. Additionally blocked colors: #18424C, #000000, #99d9EA, #FFFFFF.
                            <p><b>MOVE:DIRECTION</b></p> - Change DIRECTION to (N, S, W, E, NORTH, SOUTH, WEST or EAST). You'll expand in the given direction.
                            <p><b>TAKE:ID</b></p> - Change ID to actual territory ID. You'll expand to given territory. This only works if your territory borders to it.
                            <p><b>UPDATE_AVATAR</b></p> - Force your avatar to be updated on the map from the main site.

                            <p><b>CREATE_HOUSE:NAME</b></p> - Change NAME to actual house name. Creates a new house under your command. Your map color will now be the house's color and other users can join your house. House allies act much like a single player would on the map. Taking an ally's territory is impossible. Your house starts with your color.
                            <p><b>HOUSE_COLOR:#000000</b></p> - Change #000000 to desired color. Changes your current house's map and name color. Only works if you own the house you're in. Colors have to be unique across all houses. Additionally blocked colors: #18424C, #000000, #99d9EA, #FFFFFF.
                            <p><b>HOUSE_NAME:NAME</b></p> - Change NAME to actual house name. Changes your current house's name. Only works if you own the house you're in. Names can contain any characters, including spaces, and can be up to 24 characters long.
                            <p><b>JOIN_HOUSE:NAME</b></p> - Change NAME to actual house name. Join an existing house. Be aware that house territories need to be connected, so if you join a house you don't already border, the house will get cut, keeping the largest cluster.
                            <p><b>LEAVE_HOUSE</b></p> - Leaves your current house. Disbands the house if you own it. All your territory will be transferred to house owner upon leaving.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </transition>
</template>

<script>
    export default {
        data() {
            return {
                show: false
            };
        },

        mounted() {
            Bus.$on('toggle-help-modal', () => {
                this.show = !this.show;
            });
        },

        methods: {
            close: function() {
                Bus.$emit('toggle-help-modal');
            }
        }
    }
</script>