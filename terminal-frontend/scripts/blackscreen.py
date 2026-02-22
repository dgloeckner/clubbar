#!/usr/bin/env python3
import gi
gi.require_version('Gtk', '3.0')
from gi.repository import Gtk, Gdk

class BlackScreen(Gtk.Window):
    def __init__(self):
        super().__init__()
        self.set_decorated(False)
        self.fullscreen()
        self.override_background_color(Gtk.StateFlags.NORMAL, Gdk.RGBA(0, 0, 0, 1))
        self.connect("button-press-event", lambda *a: Gtk.main_quit())
        self.connect("touch-event", lambda *a: Gtk.main_quit())
        self.connect("key-press-event", lambda *a: Gtk.main_quit())
        self.set_events(Gdk.EventMask.BUTTON_PRESS_MASK |
                       Gdk.EventMask.TOUCH_MASK |
                       Gdk.EventMask.KEY_PRESS_MASK)
        self.show_all()

BlackScreen()
Gtk.main()
