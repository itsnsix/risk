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
                            All commands are given in the format of <b>COMMAND:VALUE</b>, and multiple commands can be sent at once with a <b>,</b> separating them i.e <b>MOVE:NORTH,COLOR:#23E4DF</b>
                        </div>

                        <div class="info-section">
                            <p><b><u>Commands available:</u></b></p>
                            <p><b>START:ID</b></p> - Change ID to actual territory ID. Set your starting position for your first territory. Only works on unoccupied territories.
                            You start in a random unoccupied area if not given (or completely random if the map is full).
                            <p><b>COLOR:#000000</b></p> - Change #000000 to desired color. Changes your map and name color.
                            <p><b>MOVE:DIRECTION</b></p> - Change DIRECTION to (N, S, W, E, NORTH, SOUTH, WEST or EAST). You'll expand in the given direction.
                            <p><b>TAKE:ID</b></p> - Change ID to actual territory ID. You'll expand to given territory. This only works if your territory borders to it.
                            <p><b>AVATAR:UPDATE</b></p> - Force your avatar to be updated on the map from the main site.
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