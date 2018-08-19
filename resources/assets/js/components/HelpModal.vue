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
                            <b>Replace {} with your value. For example {ID} becomes 1234.</b>
                            <p><b><u>Commands available:</u></b></p>
                            <p><b>MOVE:{DIRECTION}</b></p> - You'll expand in the given direction (Valid: N, S, W, E, NORTH, SOUTH, WEST or EAST).
                            <p><b>TAKE:{ID}</b></p> - You'll expand to given territory (supply the territory's id which is shown when you click the territory i.e TAKE:1323). This only works if your territory borders to it.
                            <p><b>COLOR:{#000000}</b></p> - Change your color.
                            <p><b>START:{ID}</b></p> - Change your starting position for when you're wiped off the map and restart.
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